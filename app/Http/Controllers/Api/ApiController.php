<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\CartController;
use App\Models\AppSetting;
use App\Models\{ActivityLog, City, State, Country, DeliveryAddress, OrderNote, Product, ProductVariant, Store, ProductImage, Testimonial, Shipping};
use App\Models\Tax;
use App\Models\Category;
use App\Models\{Utility, Wishlist, FlashSale};
use App\Payments\RetiredGateways;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Traits\ApiResponser;
use Session;
use App\Events\AddAdditionalFields;
use App\Models\Cart;
use App\Models\Coupon;
use App\Models\User;
use App\Models\Plan;
use App\Models\Order;
use App\Models\TaxOption;
use App\Models\TaxMethod;
use App\Models\OrderBillingDetail;
use App\Models\OrderTaxDetail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Cookie;
use Carbon\Carbon;
use App\Models\Newsletter;
use App\Models\Customer;
use App\Models\ShippingMethod;
use Log;
use App\Models\ShippingZone;
use App\Models\Setting;
use App\Models\ProductAttributeOption;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;



use App\Models\StoreDevice;




class ApiController extends Controller
{
    use ApiResponser;

    /** @var \App\Services\PaymentVerificationService|null */
    private $paymentVerifierInstance = null;

    /**
     * The single seam that answers "may this order be recorded as Paid?".
     *
     * Lazily constructed rather than injected, because this controller is
     * instantiated directly in several places (`new ApiController()`) as well as
     * resolved from the container, and a constructor argument would break those
     * call sites.
     *
     * @see \App\Services\PaymentVerificationService
     */
    private function paymentVerifier(): \App\Services\PaymentVerificationService
    {
        return $this->paymentVerifierInstance ??= new \App\Services\PaymentVerificationService();
    }











public function saveStoreFcmToken(Request $request)
{
    $validator = \Validator::make($request->all(), [
        'store_id' => ['required', 'integer'],
        'fcm_token' => ['required', 'string'],
        'device_id' => ['nullable', 'string'],
        'platform' => ['nullable', 'string'],
    ]);

    if ($validator->fails()) {
        $message = $validator->getMessageBag()->first();

        return $this->error([
            'message' => $message,
        ]);
    }

    /*
     * نفس أسلوب التحقق المستخدم في dashboard.
     */
    $user = Auth::user();

    if (empty($user)) {
        return $this->error([
            'message' => __('User not found.'),
        ]);
    }

    $store = Store::where('id', $request->store_id)
        ->where('created_by', $user->id)
        ->first();

    if (empty($store)) {
        return $this->error([
            'message' => __('Store not found.'),
        ]);
    }

    try {
        DB::transaction(function () use ($request, $user, $store) {

            /*
             * تعطيل نفس الجهاز من المتاجر الأخرى.
             */
            if (!empty($request->device_id)) {
                StoreDevice::where('user_id', $user->id)
                    ->where('device_id', $request->device_id)
                    ->update([
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
            } else {
                StoreDevice::where('fcm_token', $request->fcm_token)
                    ->update([
                        'is_active' => false,
                        'updated_at' => now(),
                    ]);
            }

            /*
             * تسجيل التوكن للمتجر المختار.
             */
            StoreDevice::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'store_id' => $store->id,
                    'device_id' => $request->device_id,
                ],
                [
                    'fcm_token' => $request->fcm_token,
                    'platform' => $request->platform,
                    'is_active' => true,
                    'updated_at' => now(),
                ]
            );
        });

        return $this->success([
            'message' => __('FCM token saved successfully.'),
            'store_id' => $store->id,
        ]);
    } catch (\Throwable $exception) {
        \Log::error('Saving store FCM token failed.', [
            'user_id' => $user->id,
            'store_id' => $store->id,
            'message' => $exception->getMessage(),
        ]);

        return $this->error([
            'message' => __('Something went wrong.'),
        ]);
    }
}


    public function cart_list(Request $request, $slug = '')
    {
        if (!auth('customers')->user()) {
            $rules = [
                'customer_id' => 'required',
            ];

            $customer_id = $request->customer_id;
        } else {
            $rules = [];
            $customer_id = auth('customers')->user()->id;
        }
        $customer = Customer::find($customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $store = getStore($slug);
        if ($store) {
            $store_id = $store->id;
            $slug = $store->slug;
        } else {
            $store_id = auth('customers')->user()->store_id ?? getCurrentStore();
        }

        $shipping_price = (int)$request['shipping_final_price'] ?? 0;

        $coupon_amount = 0;
        $Carts = Cart::where('customer_id', $customer_id)->where('store_id', $store->id)->orderBy('id', 'desc')->get();
        $cart_array = [];
        $final_price = $original_price = 0;
        $discount_price = $coupon_price = $tax_price = 0;
        $after_discount_final_price = 0;
        $cart_total_qty = 0;
        $cart_final_price = 0;
        $total_orignal_price = 0;
        $tax_id = null;
        $shipping_original_price = 0;
        $totalQtyDiscount = 0;
        $cart_array['product_list'] = [];
        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
        $out_of_stock = $settings['out_of_stock_threshold'] ?? 0;

        if (isset($request->billing_info)) {
            $other_info = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : ($request->billing_info ?? '');
            if ($other_info) {
                $con = isset($other_info['delivery_country']) ? Country::where('id', $other_info['delivery_country'])->first() : null;
                $state = isset($other_info['delivery_state']) ? State::where('id', $other_info['delivery_state'])->first() : null;
                $city = isset($other_info['delivery_city']) ? City::where('id', $other_info['delivery_city'])->first() : null;
            }
            $city_id = !empty($city->id) ?  $city->id : null;
            $state_id = !empty($state->id) ?  $state->id : null;
            $country = !empty($con->id) ?  $con->id : null;
        } else {

            $country = isset($request['countryId']) ? $request['countryId'] : null;
            $state_id = isset($request['stateId']) ? $request['stateId'] : null;
            $city_id = isset($request['cityId']) ? $request['cityId'] : null;
        }
        foreach ( $Carts as $key => $value ) {
            $cart_product_data = Product::find( $value->product_id);
            if (module_is_active('WholesaleProduct')) {
                if (!module_is_active('SizeGuideline')) {
                    if (module_is_active('WholesaleProduct')) {
                        $ProductVariant = ProductVariant::find($value->variant_id);
                        $request_value =[
                            'qty' => $value->qty,
                            'varint' => $ProductVariant->variant ?? '',
                            'product_id' => $value->product_id,
                        ];
                        $whole_sale_pricing = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price($cart_product_data, $request_value,$store);
                        if ( !empty( $cart_product_data->variant_product ) || $cart_product_data->variant_product == 0 ) {
                            $ProductVariant = ProductVariant::where( 'id', $value->variant_id )
                            ->where( 'product_id', $value->product_id )
                            ->first();
                             if($ProductVariant){
                                $variationOptions = explode( ',', $ProductVariant->variation_option );
                                $option = in_array( 'manage_stock', $variationOptions );
                                if ( $option  == true ) {
                                    if ( empty( $ProductVariant ) ) {
                                        return $this->error( [ 'message' => __('Product not found.') ],  __('Product not found.'));
                                    } else {
                                        if ( $ProductVariant->stock < $out_of_stock && $ProductVariant->stock_order_status == 'not_allow' ) {
                                            return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                        }
                                    }
                                }
                             }
                            $pricing = $whole_sale_pricing['final_price'] ?? 0;
                            $final_price = $pricing;
                            $per_product_discount_price = !empty( $value->product_data->discount_price ) ? $value->product_data->discount_price : 0;
                            $product_discount_price = $per_product_discount_price * $value->qty;

                            $product_orignal_price = !empty( $whole_sale_pricing['original_price']) ? $whole_sale_pricing['original_price'] : 0;
                            if (is_string($product_orignal_price)) {
                                preg_match('/\d+/', $product_orignal_price, $matches);
                                $product_orignal_price = (int)$matches[0];
                            }
                            $total_product_orignal_price = $product_orignal_price;

                        } else {
                            if ( !empty( $cart_product_data ) ) {
                                if ( $cart_product_data->variant_product == 1 ) {
                                    $products_data = ProductAttributeOption::find($request->variant_id);
                                    if($products_data)
                                    {
                                        $product_stock_datas = ProductVariant::where('variant',$products_data->terms)->where('product_id',$request->product_id)->first();
                                    }else{
                                        $product_stock_datas = ProductVariant::find($request->variant_id);
                                    }
                                    if(empty($product_stock_datas))
                                    {
                                        $product_stock_datas = ProductVariant::find($request->variant_id);
                                    }
                                    $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;

                                    $cart_product_data->setAttribute( 'variantId', $variant_id );
                                    $var_stock = !empty( $product_stock_datas->stock ) ? $product_stock_datas->stock : $cart_product_data->product_stock;

                                    if ( empty( $product_stock_datas->id ) || $product_stock_datas->id == 0 ) {
                                        {
                                            return $this->error( [ 'message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                        }
                                    } else if ( $var_stock <= $out_of_stock && $cart_product_data->stock_order_status == 'not_allow' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.')], __( 'Product has out of stock.') );
                                    } else {
                                        $product_stock_data = ProductVariant::find( $product_stock_datas->id );
                                        if ( $product_stock_data && $product_stock_data->stock_status == 'out_of_stock' ) {
                                            return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                        }
                                    }
                                } else {
                                    if ( $cart_product_data->product_stock <= $out_of_stock ?? 0&& $cart_product_data->stock_order_status == 'not_allow' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    }
                                }
                                $pricing = $whole_sale_pricing['final_price'] ?? 0;
                                $final_price = $pricing;
                                $per_product_discount_price = !empty( $ProductVariant->discount_price ) ? $ProductVariant->discount_price : 0;
                                $product_discount_price = $ProductVariant->discount_price;

                                $product_orignal_price = !empty( $whole_sale_pricing['original_price']) ? $whole_sale_pricing['original_price'] : 0;
                                $total_product_orignal_price = $product_orignal_price;
                            } else {
                                return $this->error( [ 'message' => __('Product not found.')], __('Product not found.'));
                            }
                        }
                    }else{
                        if ( empty( $value->variant_id ) && $value->variant_id == 0 ) {
                            $per_product_discount_price = !empty( $value->product_data->discount_price ) ? $value->product_data->discount_price : 0;
                            $product_discount_price = $per_product_discount_price * $value->qty;

                            $final_price = Product::ProductPrice($store->slug, $value->product_data->id,$value->product_data->variant_id);

                            $final_price = $final_price * $value->qty;

                            $product_orignal_price = !empty( $value->product_data->sale_price ) ? $value->product_data->sale_price : 0;
                            $total_product_orignal_price = $product_orignal_price * $value->qty;
                        } else {
                            $ProductVariant = ProductVariant::find( $value->variant_id );

                            $per_product_discount_price = !empty( $ProductVariant->discount_price ) ? $ProductVariant->discount_price : 0;
                            $product_discount_price = $ProductVariant->discount_price * $value->qty;

                            $final_price = Product::ProductPrice($store->slug, $value->product_id,$ProductVariant->id);
                            $final_price = $final_price * $value->qty;

                            $product_orignal_price = !empty( $ProductVariant->original_price ) ? $ProductVariant->original_price : 0;
                            $total_product_orignal_price = $product_orignal_price * $value->qty;
                        }
                    }
                } else {
                    if (module_is_active('WholesaleProduct')) {
                        $ProductVariant = ProductVariant::find($value->variant_id);
                        $request_value =[
                            'qty' => $value->qty,
                            'varint' => $ProductVariant->variant ?? '',
                            'product_id' => $value->product_id,
                        ];
                        $whole_sale_pricing = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price($cart_product_data, $request_value,$store);
                        if ( !empty( $cart_product_data->variant_product ) || $cart_product_data->variant_product == 0 ) {
                            $ProductVariant = ProductVariant::where( 'id', $value->variant_id )
                            ->where( 'product_id', $value->product_id )
                            ->first();
                             if($ProductVariant){
                                $variationOptions = explode( ',', $ProductVariant->variation_option );
                                $option = in_array( 'manage_stock', $variationOptions );
                                if ( $option  == true ) {
                                    if ( empty( $ProductVariant ) ) {
                                        return $this->error( [ 'message' => __('Product not found.') ],  __('Product not found.'));
                                    } else {
                                        if ( $ProductVariant->stock < $out_of_stock && $ProductVariant->stock_order_status == 'not_allow' ) {
                                            return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                        }
                                    }
                                }
                             }
                            $pricing = $whole_sale_pricing['final_price'] ?? 0;
                            $final_price = $pricing;
                            $per_product_discount_price = !empty( $value->product_data->discount_price ) ? $value->product_data->discount_price : 0;
                            $product_discount_price = $per_product_discount_price;

                            $product_orignal_price = !empty( $whole_sale_pricing['original_price']) ? $whole_sale_pricing['original_price'] : 0;
                            if (is_string($product_orignal_price)) {
                                preg_match('/\d+/', $product_orignal_price, $matches);
                                $product_orignal_price = (int)$matches[0];
                            }
                            $total_product_orignal_price = $product_orignal_price;
                        } else {
                            if ( !empty( $cart_product_data ) ) {
                                if ( $cart_product_data->variant_product == 1 ) {
                                    $products_data = ProductAttributeOption::find($request->variant_id);
                                    if($products_data)
                                    {
                                        $product_stock_datas = ProductVariant::where('variant',$products_data->terms)->where('product_id',$request->product_id)->first();
                                    }else{
                                        $product_stock_datas = ProductVariant::find($request->variant_id);
                                    }
                                    if(empty($product_stock_datas))
                                    {
                                        $product_stock_datas = ProductVariant::find($request->variant_id);
                                    }
                                    $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;

                                    $cart_product_data->setAttribute( 'variantId', $variant_id );
                                    $var_stock = !empty( $product_stock_datas->stock ) ? $product_stock_datas->stock : $cart_product_data->product_stock;

                                    if ( empty( $product_stock_datas->id ) || $product_stock_datas->id == 0 ) {
                                        {
                                            return $this->error( [ 'message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                        }
                                    } else if ( $var_stock <= $out_of_stock && $cart_product_data->stock_order_status == 'not_allow' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.')], __( 'Product has out of stock.') );
                                    } else {
                                        $product_stock_data = ProductVariant::find( $product_stock_datas->id );
                                        if ( $product_stock_data && $product_stock_data->stock_status == 'out_of_stock' ) {
                                            return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                        }
                                    }
                                } else {
                                    if ( $cart_product_data->product_stock <= $out_of_stock ?? 0&& $cart_product_data->stock_order_status == 'not_allow' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    }
                                }
                                $pricing = $whole_sale_pricing['final_price'] ?? 0;
                                $final_price = $pricing;
                                $per_product_discount_price = !empty( $ProductVariant->discount_price ) ? $ProductVariant->discount_price : 0;
                                $product_discount_price = $ProductVariant->discount_price;

                                $product_orignal_price = !empty( $whole_sale_pricing['original_price']) ? $whole_sale_pricing['original_price'] : 0;
                                $total_product_orignal_price = $product_orignal_price;
                            } else {
                                return $this->error( [ 'message' => __('Product not found.')], __('Product not found.'));
                            }
                        }
                    }
                    if (module_is_active('SizeGuideline') && isset($value->size_data)) {
                        $ProductVariant = ProductVariant::find($value->variant_id);
                        $request_value =[
                            'qty' => $value->qty,
                            'varint' => $ProductVariant->variant ?? '',
                            'product_id' => $value->product_id,
                            'size_data' => $value->size_data,
                        ];
                        $chart_pricing = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($cart_product_data, $request_value,$store);
                        if ( !empty( $cart_product_data->variant_product ) || $cart_product_data->variant_product == 0 ) {
                            $ProductVariant = ProductVariant::where( 'id', $value->variant_id )
                            ->where( 'product_id', $value->product_id )
                            ->first();
                            if($ProductVariant){
                                $variationOptions = explode( ',', $ProductVariant->variation_option );
                                $option = in_array( 'manage_stock', $variationOptions );
                                if ( $option  == true ) {
                                    if ( empty( $ProductVariant ) ) {
                                        return $this->error( [ 'message' => __('Product not found.') ],  __('Product not found.'));
                                    } else {
                                        if ( $ProductVariant->stock < $out_of_stock && $ProductVariant->stock_order_status == 'not_allow' ) {
                                            return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                        }
                                    }
                                }
                            }
                            $pricing = $chart_pricing['final_price'] ?? 0;
                            $final_price = $pricing;
                            $per_product_discount_price = !empty( $value->product_data->discount_price ) ? $value->product_data->discount_price : 0;
                            $product_discount_price = $per_product_discount_price;

                            $product_orignal_price = !empty( $chart_pricing['original_price']) ? $chart_pricing['original_price'] : 0;
                            if (is_string($product_orignal_price)) {
                                preg_match('/\d+/', $product_orignal_price, $matches);
                                $product_orignal_price = (int)$matches[0];
                            }
                            $total_product_orignal_price = $product_orignal_price;

                        } else {
                            if ( !empty( $cart_product_data ) ) {
                                if ( $cart_product_data->variant_product == 1 ) {
                                    $products_data = ProductAttributeOption::find($request->variant_id);
                                    if($products_data)
                                    {
                                        $product_stock_datas = ProductVariant::where('variant',$products_data->terms)->where('product_id',$request->product_id)->first();
                                    }else{
                                        $product_stock_datas = ProductVariant::find($request->variant_id);
                                    }
                                    if(empty($product_stock_datas))
                                    {
                                        $product_stock_datas = ProductVariant::find($request->variant_id);
                                    }
                                    $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;

                                    $cart_product_data->setAttribute( 'variantId', $variant_id );
                                    $var_stock = !empty( $product_stock_datas->stock ) ? $product_stock_datas->stock : $cart_product_data->product_stock;

                                    if ( empty( $product_stock_datas->id ) || $product_stock_datas->id == 0 ) {
                                        {
                                            return $this->error( [ 'message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                        }
                                    } else if ( $var_stock <= $out_of_stock && $cart_product_data->stock_order_status == 'not_allow' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.')], __( 'Product has out of stock.') );
                                    } else {
                                        $product_stock_data = ProductVariant::find( $product_stock_datas->id );
                                        if ( $product_stock_data && $product_stock_data->stock_status == 'out_of_stock' ) {
                                            return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                        }
                                    }
                                } else {
                                    if ( $cart_product_data->product_stock <= $out_of_stock ?? 0&& $cart_product_data->stock_order_status == 'not_allow' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    }
                                }
                                $pricing = $chart_pricing['final_price'] ?? 0;
                                $final_price = $pricing * $value->qty;
                                $per_product_discount_price = !empty( $ProductVariant->discount_price ) ? $ProductVariant->discount_price : 0;
                                $product_discount_price = $ProductVariant->discount_price * $value->qty;

                                $product_orignal_price = !empty( $chart_pricing['original_price']) ? $chart_pricing['original_price'] : 0;
                                $total_product_orignal_price = $product_orignal_price * $value->qty;
                            } else {
                                return $this->error( [ 'message' => __('Product not found.')], __('Product not found.'));
                            }
                        }
                    }
                }
            } elseif (module_is_active('SizeGuideline')) {
                if (module_is_active('SizeGuideline') && isset($value->size_data)) {
                    $ProductVariant = ProductVariant::find($value->variant_id);
                    $request_value =[
                        'qty' => $value->qty,
                        'varint' => $ProductVariant->variant ?? '',
                        'product_id' => $value->product_id,
                        'size_data' => $value->size_data,
                    ];
                    $chart_pricing = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($cart_product_data, $request_value,$store);
                    if ( !empty( $cart_product_data->variant_product ) || $cart_product_data->variant_product == 0 ) {
                        $ProductVariant = ProductVariant::where( 'id', $value->variant_id )
                        ->where( 'product_id', $value->product_id )
                        ->first();
                        if($ProductVariant){
                            $variationOptions = explode( ',', $ProductVariant->variation_option );
                            $option = in_array( 'manage_stock', $variationOptions );
                            if ( $option  == true ) {
                                if ( empty( $ProductVariant ) ) {
                                    return $this->error( [ 'message' => __('Product not found.') ],  __('Product not found.'));
                                } else {
                                    if ( $ProductVariant->stock < $out_of_stock && $ProductVariant->stock_order_status == 'not_allow' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                    }
                                }
                            }
                        }
                        $pricing = $chart_pricing['final_price'] ?? 0;
                        $final_price = $pricing * $value->qty;
                        $per_product_discount_price = !empty( $value->product_data->discount_price ) ? $value->product_data->discount_price : 0;
                        $product_discount_price = $per_product_discount_price * $value->qty;

                        $product_orignal_price = !empty( $chart_pricing['original_price']) ? $chart_pricing['original_price'] : 0;
                        if (is_string($product_orignal_price)) {
                            preg_match('/\d+/', $product_orignal_price, $matches);
                            $product_orignal_price = (int)$matches[0];
                        }
                        $total_product_orignal_price = $product_orignal_price;

                    } else {
                        if ( !empty( $cart_product_data ) ) {
                            if ( $cart_product_data->variant_product == 1 ) {
                                $products_data = ProductAttributeOption::find($request->variant_id);
                                if($products_data)
                                {
                                    $product_stock_datas = ProductVariant::where('variant',$products_data->terms)->where('product_id',$request->product_id)->first();
                                }else{
                                    $product_stock_datas = ProductVariant::find($request->variant_id);
                                }
                                if(empty($product_stock_datas))
                                {
                                    $product_stock_datas = ProductVariant::find($request->variant_id);
                                }
                                $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;

                                $cart_product_data->setAttribute( 'variantId', $variant_id );
                                $var_stock = !empty( $product_stock_datas->stock ) ? $product_stock_datas->stock : $cart_product_data->product_stock;

                                if ( empty( $product_stock_datas->id ) || $product_stock_datas->id == 0 ) {
                                    {
                                        return $this->error( [ 'message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                    }
                                } else if ( $var_stock <= $out_of_stock && $cart_product_data->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.')], __( 'Product has out of stock.') );
                                } else {
                                    $product_stock_data = ProductVariant::find( $product_stock_datas->id );
                                    if ( $product_stock_data && $product_stock_data->stock_status == 'out_of_stock' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                    }
                                }
                            } else {
                                if ( $cart_product_data->product_stock <= $out_of_stock ?? 0&& $cart_product_data->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                }
                            }
                            $pricing = $chart_pricing['final_price'] ?? 0;
                            $final_price = $pricing * $value->qty;
                            $per_product_discount_price = !empty( $ProductVariant->discount_price ) ? $ProductVariant->discount_price : 0;
                            $product_discount_price = $ProductVariant->discount_price * $value->qty;

                            $product_orignal_price = !empty( $chart_pricing['original_price']) ? $chart_pricing['original_price'] : 0;
                            $total_product_orignal_price = $product_orignal_price * $value->qty;
                        } else {
                            return $this->error( [ 'message' => __('Product not found.')], __('Product not found.'));
                        }
                    }
                }else{
                    if ( empty( $value->variant_id ) && $value->variant_id == 0 ) {
                        $per_product_discount_price = !empty( $value->product_data->discount_price ) ? $value->product_data->discount_price : 0;
                        $product_discount_price = $per_product_discount_price * $value->qty;

                        $final_price = Product::ProductPrice($store->slug, $value->product_data->id,$value->product_data->variant_id);

                        $final_price = $final_price * $value->qty;

                        $product_orignal_price = !empty( $value->product_data->sale_price ) ? $value->product_data->sale_price : 0;
                        $total_product_orignal_price = $product_orignal_price * $value->qty;
                    } else {
                        $ProductVariant = ProductVariant::find( $value->variant_id );

                        $per_product_discount_price = !empty( $ProductVariant->discount_price ) ? $ProductVariant->discount_price : 0;
                        $product_discount_price = $ProductVariant->discount_price * $value->qty;

                        $final_price = Product::ProductPrice($store->slug, $value->product_id,$ProductVariant->id);
                        $final_price = $final_price * $value->qty;

                        $product_orignal_price = !empty( $ProductVariant->original_price ) ? $ProductVariant->original_price : 0;
                        $total_product_orignal_price = $product_orignal_price * $value->qty;
                    }
                }
            } else {
                if ( empty( $value->variant_id ) && $value->variant_id == 0 ) {
                    $per_product_discount_price = !empty( $value->product_data->discount_price ) ? $value->product_data->discount_price : 0;
                    $product_discount_price = $per_product_discount_price * $value->qty;

                    $final_price = Product::ProductPrice($store->slug, $value->product_data->id,$value->product_data->variant_id);
                    $final_price = $final_price * $value->qty;

                    $product_orignal_price = !empty($value->product_data->sale_price) ? $value->product_data->sale_price : 0;
                    $total_product_orignal_price = $product_orignal_price * $value->qty;
                } else {
                    $ProductVariant = ProductVariant::find($value->variant_id);

                    $per_product_discount_price = !empty($ProductVariant->discount_price) ? $ProductVariant->discount_price : 0;
                    $product_discount_price = $ProductVariant->discount_price * $value->qty;
                    $final_price = Product::ProductPrice($store->slug, $value->product_id,$ProductVariant->id);
                    $final_price = $final_price * $value->qty;

                    $product_orignal_price = !empty($ProductVariant->original_price) ? $ProductVariant->original_price : 0;
                    $total_product_orignal_price = $product_orignal_price * $value->qty;
                }
            }
            $cart_array[ 'product_list' ][ $key ][ 'cart_id' ] = $value->id;
            $cart_array[ 'product_list' ][ $key ][ 'cart_created' ] = $value->created_at;
            $cart_array[ 'product_list' ][ $key ][ 'product_id' ] = $value->product_id;
            
            // Handle digital product images
            if ($cart_product_data->product_type === 'digital') {
                if (class_exists('\Workdo\DigitalProduct\app\Models\DigitalProductImage')) {
                    $digitalImage = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $cart_product_data->id)->first();
                    if ($digitalImage && !empty($digitalImage->image_path)) {
                        $cart_array[ 'product_list' ][ $key ][ 'image' ] = $digitalImage->image_path;
                    } else {
                        $cart_array[ 'product_list' ][ $key ][ 'image' ] = !empty( $value->product_data->cover_image_path ) ? $value->product_data->cover_image_path : ' ';
                    }
                } else {
                    $cart_array[ 'product_list' ][ $key ][ 'image' ] = !empty( $value->product_data->cover_image_path ) ? $value->product_data->cover_image_path : ' ';
                }
            } else {
                $cart_array[ 'product_list' ][ $key ][ 'image' ] = !empty( $value->product_data->cover_image_path ) ? $value->product_data->cover_image_path : ' ';
            }
            $cart_array[ 'product_list' ][ $key ][ 'name' ] = !empty( $value->product_data->name ) ? $value->product_data->name : ' ';
            $cart_array[ 'product_list' ][ $key ][ 'orignal_price' ] = SetNumber( $product_orignal_price );
            $cart_array[ 'product_list' ][ $key ][ 'total_orignal_price' ] = SetNumber( $total_product_orignal_price );
            $cart_array[ 'product_list' ][ $key ][ 'per_product_discount_price' ] = SetNumber( $per_product_discount_price );
            $cart_array[ 'product_list' ][ $key ][ 'discount_price' ] = SetNumber( $product_discount_price );
            $cart_array[ 'product_list' ][ $key ][ 'final_price' ] = SetNumber( $final_price );
            $cart_array[ 'product_list' ][ $key ][ 'qty' ] = $value->qty;
            $cart_array[ 'product_list' ][ $key ][ 'variant_id' ] = $value->variant_id;
            $cart_array[ 'product_list' ][ $key ][ 'variant_name' ] = !empty( $value->variant_data->variant ) ? $value->variant_data->variant : '';
            $cart_array[ 'product_list' ][ $key ][ 'return' ] = 0;
            $cart_array[ 'product_list' ][ $key ][ 'shipping_price' ] = SetNumber( $shipping_price );

            if (module_is_active('ProductPricing')) {
                $admin  = User::find($store->created_by ?? null) ?? null;
                $plan   = Plan::find($admin->plan_id ?? null) ?? null;
                $productPricingRules    = \Workdo\ProductPricing\app\Models\ProductPricingRule::where('store_id',$store->id)->get();
                if (isset($plan->modules) && strpos($plan->modules, 'ProductPricing') !== false && count($productPricingRules) != 0 && empty($value->variant_id) && $value->variant_id == 0){
                    $product_list_values = [
                        'product_discount_price'        => $product_discount_price,
                        'final_price'                   => $final_price,
                        'product_orignal_price'         => $product_orignal_price,
                        'total_product_orignal_price'   => $total_product_orignal_price,
                    ];
                    $productPricing = \Workdo\ProductPricing\app\Models\ProductPricingRule::productPricingApply($Carts,$value,$store,$cart_product_data,$product_list_values,$totalQtyDiscount);
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
            $cart_total_qty += $value->qty;
            $cart_final_price += $final_price;
            $original_price += $total_product_orignal_price;
            $shipping_original_price += $shipping_price;
            if (isset($request['coupon_code'])) {
                $coupon = Coupon::whereRaw('BINARY `coupon_code` = ?', [$request['coupon_code']])->whereDate('coupon_expiry_date', '>=', date('Y-m-d'))->first();
                if ($coupon) {
                    $coupon_apply_price = Cart::getCouponTotalAmount($coupon, $final_price, $cart_product_data->id, $cart_product_data->category_id);

                    $coupon_price += $final_price - $coupon_apply_price;
                    $final_price = $coupon_apply_price;
                }
            }
            if ($cart_product_data->tax_id) {
                $tax_id = $cart_product_data->tax_id;
                $tax_price += Cart::getProductTaxAmount($cart_product_data->tax_id, $final_price, $store->id, $city_id, $state_id, $country, true);
            } else {
                $tax_price += 0;
            }
        }
        $after_discount_final_price = $cart_final_price;

        $product_discount_price = (float)number_format((float)$discount_price, 2);
        $cart_array['product_discount_price'] = $product_discount_price;
        $after_discount_final_price = (float)$after_discount_final_price;

        $cart_array['sub_total'] = $after_discount_final_price + $shipping_price;

        $tax_option = TaxOption::where('store_id', $store_id)
            
            ->pluck('value', 'name')->toArray();
        $tax_rate = 0;
        $tax_name = null;
        if (isset($cart_product_data)) {
            if ($cart_product_data->tax_id !== null && $city_id === null && $state_id === null && $country === null) {
                $data['store_id'] = $store->id;
                $data['product_tax_id'] = $cart_product_data->tax_id;
                $data['sub_total'] = $after_discount_final_price;
                $data['product_original_price'] = $cart_array['sub_total'];
                $taxes  = Tax::TaxCount($data);
                $tax_price = $tax_price;
                $tax_rate = $taxes['tax_rate'] ?? 0;
                $tax_name = $taxes['tax_name'] ?? null;
            }
        }

        if ($coupon_price == '') {
            $final_total = $cart_final_price + $shipping_price + $tax_price;
        } else {

            $final_total = $cart_final_price - $coupon_price + $shipping_price + $tax_price;
        }

        if (isset($request['coupon_code'])) {
            $coupon = Coupon::whereRaw('BINARY `coupon_code` = ?', [$request['coupon_code']])->first();
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
                        $coupon = null;
                    }
                }
                if (empty($coupon_expiry_date)) {
                    $coupon = null;
                }
            }
        }

        $cart_array['coupon_info'] = [];
        $cart_array['coupon_info']['coupon_id'] = $coupon->id ?? 0;
        $cart_array['coupon_info']['coupon_name'] = $coupon->coupon_name ?? '-';
        $cart_array['coupon_info']['coupon_code'] = $coupon->coupon_code ?? '-';
        $cart_array['coupon_info']['coupon_discount_type'] = $coupon->coupon_type ?? 'percentage';
        $cart_array['coupon_info']['coupon_discount_number'] = SetNumber($coupon->discount_amount ?? 0);
        $cart_array['coupon_info']['coupon_discount_amount'] = SetNumber($coupon_price ?? 0);
        $cart_array['coupon_info']['coupon_final_amount'] = SetNumber($final_price ?? 0);

        $cart_array['tax_price'] = SetNumber($tax_price);
        $cart_array['total_tax_price'] = SetNumber($tax_price);
        $cart_array['tax_id'] = $tax_id ?? 0;
        $cart_array['tax_rate'] = $tax_rate ?? 0;
        $cart_array['tax_type'] = 'Percentage';
        $cart_array['tax_name'] = $tax_name ?? 'Tax';
        $cart_array['cart_total_product'] = count($Carts);
        $cart_array['cart_total_qty'] = $cart_total_qty;
        $cart_array['original_price'] = SetNumber($original_price);
        $cart_array['total_final_price'] = SetNumber($cart_final_price);
        $cart_array['final_price'] = SetNumber($cart_final_price);
        $cart_array['total_sub_price'] = SetNumber($final_total);
        $cart_array['sub_total'] = $after_discount_final_price;
        $cart_array['total_coupon_price'] = $coupon_price;
        $cart_array['shipping_original_price'] = $shipping_price;
        $cart_array['coupon_code'] =  $request['coupon_code'] ?? null;

        // deposite_amount,pending_amount
        if (module_is_active('PartialPayments') && $request['status'] == true) {
            if(\Auth::guard('customers')->user())
            {
                $param = [
                    'tax_price' => SetNumber($tax_price),
                    'shipping_price' => $shipping_price,
                    'coupon_price' => $coupon_price,
                ];
                $request = new Request($param);

                $updated_cart = \Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController::ManageDeposit($request, $store->slug);

                $cart_array['deposite_amount'] = $updated_cart['deposite_amount'] ?? 0;
                $cart_array['pending_amount'] = $updated_cart['pending_amount'] ?? 0;


            }
        }

        if (module_is_active('PreOrder') && $request['order_type'] == true) {
            if(\Auth::guard('customers')->user())
            {
                $cart_array['order_type'] = $request['order_type'] ?? '';
            }
        }

        if (!empty($cart_array)) {
            return $this->success($cart_array, __('Cart items get successfully.'));
        } else {
            return $this->error(['message' => __('Cart is empty.')], __('Cart is empty.'));
        }
    }

    public function featured_products(Request $request, $slug = '')
    {
        if ($slug == '') {
            $slug = request()->segments()[0];
            $store = getStore($slug);
        } else {
            $store = getStore($slug);
        }

        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }

        $store_id = !empty($store) ? $store->id : getCurrentStore();
        if ($slug == 'admin') {
            $SubCategory = Category::where('store_id', getCurrentStore())->limit(3)->get();
        } else {
            $SubCategory = Category::where('store_id', $store_id)->limit(3)->get();
        }
        $data = $SubCategory;
        if (!empty($data)) {
            return $this->success($data, __('Featured products get successfully.'));
        } else {
            return $this->error(['message' => 'Product category found.'], __('Product category found.'));
        }
    }

    public function addtocart(Request $request, $slug = '')
    {

        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        $settings = Utility::Setting($store->id);
        if (!auth('customers')->user()) {
            $rules = [
                'customer_id' => 'required',
                'product_id' => 'required',
                'variant_id' => 'nullable',
                'qty' => 'required',
            ];

            $customer_id = $request->customer_id;
        } else {
            $rules = [
                'product_id' => 'required',
                'variant_id' => 'nullable',
                'qty' => 'required',
            ];
            $customer_id = auth('customers')->user()->id;
        }
        $customer = Customer::find($customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $final_price = 0;
        $product = Product::find($request->product_id);
        if ($product && $product->variant_product == 1) {
            if (!module_is_active('SizeGuideline')){
                if ( empty( $request->variant_id ) || $request->variant_id == 0 ) {
                    return $this->error( [
                        'message' => __('Please Select a variant in a product.')
                    ], __('Please Select a variant in a product.'));
                } else {

                }
            }
        }

        if (module_is_active('WholesaleProduct')) {
            if (!module_is_active('SizeGuideline')) {
                if (module_is_active('WholesaleProduct')) {
                    $ProductVariant = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();
                    $request_value =[
                        'qty' => $request->qty,
                        'varint' => $ProductVariant->variant ?? '',
                        'product_id' => $request->product_id,
                    ];
                    $whole_sale_pricing = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price($product, $request_value,$store);
                    if ( !empty( $request->attribute_id ) || $request->attribute_id != 0 ) {
                        $ProductVariant = ProductVariant::where( 'id', $request->attribute_id )
                        ->where( 'product_id', $request->product_id )
                        ->first();

                        $variationOptions = explode( ',', $ProductVariant->variation_option );
                        $option = in_array( 'manage_stock', $variationOptions );
                        if ( $option  == true ) {
                            if ( empty( $ProductVariant ) ) {
                                return $this->error( [ 'message' => __('Product not found.') ],  __('Product not found.'));
                            } else {
                                if ( $ProductVariant->stock < $settings[ 'out_of_stock_threshold' ] ?? '' && $ProductVariant->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                }
                            }
                        }

                        $pricing = $whole_sale_pricing['final_price'] ?? 0;
                        $final_price = $pricing * $request->qty ;
                    } else {
                        if ( !empty( $product ) ) {
                            if ( $product->variant_product == 1 ) {
                                $products_data = ProductAttributeOption::find($request->variant_id);
                                if($products_data)
                                {
                                    $product_stock_datas = ProductVariant::where('variant',$products_data->terms)->where('product_id',$request->product_id)->first();
                                }else{
                                    $product_stock_datas = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();;

                                }
                                if(empty($product_stock_datas))
                                {
                                    $product_stock_datas = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();
                                }
                                $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;

                                $product->setAttribute( 'variantId', $variant_id );
                                $var_stock = !empty( $product_stock_datas->stock ) ? $product_stock_datas->stock : $product->product_stock;

                                if ( empty( $product_stock_datas->id ) || $product_stock_datas->id == 0 ) {
                                    {
                                        return $this->error( [ 'message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                    }
                                } else if ( $var_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.')], __( 'Product has out of stock.') );
                                } else {
                                    $product_stock_data = ProductVariant::find( $product_stock_datas->id );
                                    if ( $product_stock_data && $product_stock_data->stock_status == 'out_of_stock' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                    }
                                }
                            } else {
                                if(module_is_active('PreOrder') && isset($customer)){
                                    $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                    $pre_order_setting = \Workdo\PreOrder\app\Models\PreOrderSetting::where('product_id', $product->id)->where('status', 1)->where('store_id', $store->id)->first();
                                    if(isset($pre_order_setting) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                        if ($pre_order_setting->qty <= 0) {
                                            return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                        }
                                    }
                                }else{
                                    if ( ($product->product_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock') ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    }
                                }
                            }
                            $pricing = $whole_sale_pricing['final_price'] ?? 0;
                            $final_price = $pricing * $request->qty ;
                        } else {
                            return $this->error( [ 'message' => __('Product not found.')], __('Product not found.'));
                        }
                    }
                }else{
                    if ( !empty( $request->attribute_id ) || $request->attribute_id != 0 ) {
                        $ProductVariant = ProductVariant::where( 'id', $request->attribute_id )
                        ->where( 'product_id', $request->product_id )
                        ->first();

                        $variationOptions = explode( ',', $ProductVariant->variation_option );
                        $option = in_array( 'manage_stock', $variationOptions );
                        if ( $option  == true ) {
                            if ( empty( $ProductVariant ) ) {
                                return $this->error( [ 'message' => __('Product not found.') ],  __('Product not found.'));
                            } else {
                                if ( $ProductVariant->stock < $settings[ 'out_of_stock_threshold' ] && $ProductVariant->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                }
                            }
                        }

                        $final_price = $ProductVariant->final_price * $request->qty;
                    } else {
                        if ( !empty( $product ) ) {
                            if ( $product->variant_product == 1 ) {
                                $products_data = ProductAttributeOption::find($request->variant_id);
                                if($products_data)
                                {
                                    $product_stock_datas = ProductVariant::where('variant',$products_data->terms)->where('product_id',$request->product_id)->first();
                                }else{
                                    $product_stock_datas = ProductVariant::find($request->variant_id);
                                }
                                if(empty($product_stock_datas))
                                {
                                    $product_stock_datas = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();
                                }
                                $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;

                                $product->setAttribute( 'variantId', $variant_id );
                                $var_stock = !empty( $product_stock_datas->stock ) ? $product_stock_datas->stock : $product->product_stock;

                                if ( empty( $product_stock_datas->id ) || $product_stock_datas->id == 0 ) {
                                    {
                                        return $this->error( [ 'message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                    }
                                } else if ( $var_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.')], __( 'Product has out of stock.') );
                                } else {
                                    $product_stock_data = ProductVariant::find( $product_stock_datas->id );
                                    if ( $product_stock_data && $product_stock_data->stock_status == 'out_of_stock' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                    }
                                }
                            } else {
                                if(module_is_active('PreOrder') && isset($customer)){
                                    $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                    $pre_order_setting = \Workdo\PreOrder\app\Models\PreOrderSetting::where('product_id', $product->id)->where('status', 1)->where('store_id', $store->id)->first();
                                    if(isset($pre_order_setting) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                        if ($pre_order_setting->qty <= 0) {
                                            return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                        }
                                    }
                                }else{
                                    if ( ($product->product_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock') ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    }
                                }
                            }
                            $final_price = floatval( $product->final_price ) * floatval( $request->qty );
                        } else {
                            return $this->error( [ 'message' => __('Product not found.')], __('Product not found.'));
                        }
                    }
                }
            } else {
                if (module_is_active('WholesaleProduct')) {
                    $ProductVariant = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();
                    $request_value =[
                        'qty' => $request->qty,
                        'varint' => $ProductVariant->variant ?? '',
                        'product_id' => $request->product_id,
                    ];
                    $whole_sale_pricing = \Workdo\WholesaleProduct\app\Models\WholesaleProduct::Wholesale_Product_price($product, $request_value,$store);
                    if ( !empty( $request->attribute_id ) || $request->attribute_id != 0 ) {
                        $ProductVariant = ProductVariant::where( 'id', $request->attribute_id )
                        ->where( 'product_id', $request->product_id )
                        ->first();

                        $variationOptions = explode( ',', $ProductVariant->variation_option );
                        $option = in_array( 'manage_stock', $variationOptions );
                        if ( $option  == true ) {
                            if ( empty( $ProductVariant ) ) {
                                return $this->error( [ 'message' => __('Product not found.') ],  __('Product not found.'));
                            } else {
                                if ( $ProductVariant->stock < $settings[ 'out_of_stock_threshold' ] ?? '' && $ProductVariant->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                }
                            }
                        }

                        $pricing = $whole_sale_pricing['final_price'] ?? 0;
                        $final_price = $pricing * $request->qty ;
                    } else {
                        if ( !empty( $product ) ) {
                            if ( $product->variant_product == 1 ) {
                                $products_data = ProductAttributeOption::find($request->variant_id);
                                if($products_data)
                                {
                                    $product_stock_datas = ProductVariant::where('variant',$products_data->terms)->where('product_id',$request->product_id)->first();
                                }else{
                                    $product_stock_datas = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();;

                                }
                                if(empty($product_stock_datas))
                                {
                                    $product_stock_datas = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();
                                }
                                $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;

                                $product->setAttribute( 'variantId', $variant_id );
                                $var_stock = !empty( $product_stock_datas->stock ) ? $product_stock_datas->stock : $product->product_stock;

                                if ( empty( $product_stock_datas->id ) || $product_stock_datas->id == 0 ) {
                                    {
                                        return $this->error( [ 'message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                    }
                                } else if ( $var_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.')], __( 'Product has out of stock.') );
                                } else {
                                    $product_stock_data = ProductVariant::find( $product_stock_datas->id );
                                    if ( $product_stock_data && $product_stock_data->stock_status == 'out_of_stock' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                    }
                                }
                            } else {
                                if(module_is_active('PreOrder') && isset($customer)){
                                    $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                    $pre_order_setting = \Workdo\PreOrder\app\Models\PreOrderSetting::where('product_id', $product->id)->where('status', 1)->where('store_id', $store->id)->first();
                                    if(isset($pre_order_setting) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                        if ($pre_order_setting->qty <= 0) {
                                            return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                        }
                                    }
                                }else{
                                    if ( ($product->product_stock <= $settings[ 'out_of_stock_threshold' ] ?? '' && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock') ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    }
                                }
                            }
                            $pricing = $whole_sale_pricing['final_price'] ?? 0;
                            $final_price = $pricing * $request->qty ;
                        } else {
                            return $this->error( [ 'message' => __('Product not found.')], __('Product not found.'));
                        }
                    }
                }
                if (module_is_active('SizeGuideline')) {
                    $ProductVariant = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();
                    $request_value =[
                        'qty' => $request->qty,
                        'varint' => $ProductVariant->variant ?? '',
                        'product_id' => $request->product_id,
                        'size_data' => $request->size_data ?? '',
                    ];
                    $chart_pricing = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($product, $request_value,$store);
                    if ( !empty( $request->attribute_id ) || $request->attribute_id != 0 ) {
                        $ProductVariant = ProductVariant::where( 'id', $request->attribute_id )
                        ->where( 'product_id', $request->product_id )
                        ->first();

                        $variationOptions = explode( ',', $ProductVariant->variation_option );
                        $option = in_array( 'manage_stock', $variationOptions );
                        if ( $option  == true ) {
                            if ( empty( $ProductVariant ) ) {
                                return $this->error( [ 'message' => __('Product not found.') ],  __('Product not found.'));
                            } else {
                                if ( $ProductVariant->stock < $settings[ 'out_of_stock_threshold' ] ?? '' && $ProductVariant->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                }
                            }
                        }

                        $pricing = $chart_pricing['final_price'] ?? 0;
                        $final_price = $pricing * $request->qty ;
                    } else {
                        if ( !empty( $product ) ) {
                            if ( $product->variant_product == 1 ) {
                                $products_data = ProductAttributeOption::find($request->variant_id);
                                if($products_data)
                                {
                                    $product_stock_datas = ProductVariant::where('variant',$products_data->terms)->where('product_id',$request->product_id)->first();
                                }else{
                                    $product_stock_datas = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();;

                                }
                                if(empty($product_stock_datas))
                                {
                                    $product_stock_datas = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();
                                }
                                $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;

                                $product->setAttribute( 'variantId', $variant_id );
                                $var_stock = !empty( $product_stock_datas->stock ) ? $product_stock_datas->stock : $product->product_stock;

                                if ( empty( $product_stock_datas->id ) || $product_stock_datas->id == 0 ) {
                                    {
                                        return $this->error( [ 'message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                    }
                                } else if ( $var_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.')], __( 'Product has out of stock.') );
                                } else {
                                    $product_stock_data = ProductVariant::find( $product_stock_datas->id );
                                    if ( $product_stock_data && $product_stock_data->stock_status == 'out_of_stock' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                    }
                                }
                            } else {
                                if(module_is_active('PreOrder') && isset($customer)){
                                    $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                    $pre_order_setting = \Workdo\PreOrder\app\Models\PreOrderSetting::where('product_id', $product->id)->where('status', 1)->where('store_id', $store->id)->first();
                                    if(isset($pre_order_setting) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                        if ($pre_order_setting->qty <= 0) {
                                            return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                        }
                                    }
                                }else{
                                    if ( ($product->product_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock') ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    }
                                }
                            }
                            $pricing = $chart_pricing['final_price'] ?? 0;
                            $final_price = $pricing * $request->qty ;
                        } else {
                            return $this->error( [ 'message' => __('Product not found.')], __('Product not found.'));
                        }
                    }
                }
            }
        } elseif (module_is_active('SizeGuideline')) {
            if (module_is_active('SizeGuideline')) {
                $ProductVariant = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();
                $request_value =[
                    'qty' => $request->qty,
                    'varint' => $ProductVariant->variant ?? '',
                    'product_id' => $request->product_id,
                    'size_data' => $request->size_data ?? '',
                ];
                $chart_pricing = \Workdo\SizeGuideline\app\Models\SizeGuideline::Size_Product_price($product, $request_value,$store);
                if ( !empty( $request->attribute_id ) || $request->attribute_id != 0 ) {
                    $ProductVariant = ProductVariant::where( 'id', $request->attribute_id )
                    ->where( 'product_id', $request->product_id )
                    ->first();

                    $variationOptions = explode( ',', $ProductVariant->variation_option );
                    $option = in_array( 'manage_stock', $variationOptions );
                    if ( $option  == true ) {
                        if ( empty( $ProductVariant ) ) {
                            return $this->error( [ 'message' => __('Product not found.') ],  __('Product not found.'));
                        } else {
                            if ( $ProductVariant->stock < $settings[ 'out_of_stock_threshold' ] ?? '' && $ProductVariant->stock_order_status == 'not_allow' ) {
                                return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                            }
                        }
                    }

                    $pricing = $chart_pricing['final_price'] ?? 0;
                    $final_price = $pricing * $request->qty ;
                } else {
                    if ( !empty( $product ) ) {
                        if ( $product->variant_product == 1 ) {
                            $products_data = ProductAttributeOption::find($request->variant_id);
                            if($products_data)
                            {
                                $product_stock_datas = ProductVariant::where('variant',$products_data->terms)->where('product_id',$request->product_id)->first();
                            }else{
                                $product_stock_datas = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();;

                            }
                            if(empty($product_stock_datas))
                            {
                                $product_stock_datas = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();
                            }
                            if($request->size_data == null && $request->variant_id == null)
                            {
                                if ( empty( $product_stock_datas->id ) || $product_stock_datas->id == 0 ) {
                                    {
                                        return $this->error( [ 'message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                    }
                                }
                            }
                            if($product_stock_datas)
                            {
                                $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;
                                $product->setAttribute( 'variantId', $variant_id );
                                $var_stock = !empty( $product_stock_datas->stock ) ? $product_stock_datas->stock : $product->product_stock;

                                if ( $var_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.')], __( 'Product has out of stock.') );
                                } else {
                                    $product_stock_data = ProductVariant::find( $product_stock_datas->id );
                                    if ( $product_stock_data && $product_stock_data->stock_status == 'out_of_stock' ) {
                                        return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                    }
                                }
                            }
                        } else {
                            if(module_is_active('PreOrder') && isset($customer)){
                                $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                $pre_order_setting = \Workdo\PreOrder\app\Models\PreOrderSetting::where('product_id', $product->id)->where('status', 1)->where('store_id', $store->id)->first();
                                if(isset($pre_order_setting) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                    if ($pre_order_setting->qty <= 0) {
                                        return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    }
                                }
                            }else{
                                if ( ($product->product_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock') ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                }
                            }
                        }
                        $pricing = $chart_pricing['final_price'] ?? 0;
                        $final_price = $pricing * $request->qty ;
                    } else {
                        return $this->error( [ 'message' => __('Product not found.')], __('Product not found.'));
                    }
                }
            }else{
                if ( !empty( $request->attribute_id ) || $request->attribute_id != 0 ) {
                    $ProductVariant = ProductVariant::where( 'id', $request->attribute_id )
                    ->where( 'product_id', $request->product_id )
                    ->first();

                    $variationOptions = explode( ',', $ProductVariant->variation_option );
                    $option = in_array( 'manage_stock', $variationOptions );
                    if ( $option  == true ) {
                        if ( empty( $ProductVariant ) ) {
                            return $this->error( [ 'message' => __('Product not found.') ],  __('Product not found.'));
                        } else {
                            if ( $ProductVariant->stock < $settings[ 'out_of_stock_threshold' ] && $ProductVariant->stock_order_status == 'not_allow' ) {
                                return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                            }
                        }
                    }

                    $final_price = $ProductVariant->final_price * $request->qty;
                } else {
                    if ( !empty( $product ) ) {
                        if ( $product->variant_product == 1 ) {
                            $products_data = ProductAttributeOption::find($request->variant_id);
                            if($products_data)
                            {
                                $product_stock_datas = ProductVariant::where('variant',$products_data->terms)->where('product_id',$request->product_id)->first();
                            }else{
                                $product_stock_datas = ProductVariant::find($request->variant_id);
                            }
                            if(empty($product_stock_datas))
                            {
                                $product_stock_datas = ProductVariant::where('id',$request->variant_id)->where('product_id',$request->product_id)->first();
                            }
                            $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;

                            $product->setAttribute( 'variantId', $variant_id );
                            $var_stock = !empty( $product_stock_datas->stock ) ? $product_stock_datas->stock : $product->product_stock;

                            if ( empty( $product_stock_datas->id ) || $product_stock_datas->id == 0 ) {
                                {
                                    return $this->error( [ 'message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                }
                            } else if ( $var_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow' ) {
                                return $this->error( [ 'message' => __('Product has out of stock.')], __( 'Product has out of stock.') );
                            } else {
                                $product_stock_data = ProductVariant::find( $product_stock_datas->id );
                                if ( $product_stock_data && $product_stock_data->stock_status == 'out_of_stock' ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.') ], __('Product has out of stock.'));
                                }
                            }
                        } else {
                            if(module_is_active('PreOrder') && isset($customer)){
                                $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                                $pre_order_setting = \Workdo\PreOrder\app\Models\PreOrderSetting::where('product_id', $product->id)->where('status', 1)->where('store_id', $store->id)->first();
                                if(isset($pre_order_setting) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                    if ($pre_order_setting->qty <= 0) {
                                        return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    }
                                }
                            }else{
                                if ( ($product->product_stock <= $settings[ 'out_of_stock_threshold' ] && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock') ) {
                                    return $this->error( [ 'message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                }
                            }
                        }
                        $final_price = floatval( $product->final_price ) * floatval( $request->qty );
                    } else {
                        return $this->error( [ 'message' => __('Product not found.')], __('Product not found.'));
                    }
                }
            }
        } else {
            if ( !empty( $request->attribute_id ) || $request->attribute_id != 0 ) {
                $ProductVariant = ProductVariant::where( 'id', $request->attribute_id )
                ->where( 'product_id', $request->product_id )
                ->first();

                $variationOptions = explode(',', $ProductVariant->variation_option);
                $option = in_array('manage_stock', $variationOptions);
                if ($option  == true) {
                    if (empty($ProductVariant)) {
                        return $this->error(['message' => __('Product not found.')],  __('Product not found.'));
                    } else {
                        if ($ProductVariant->stock < $settings['out_of_stock_threshold'] && $ProductVariant->stock_order_status == 'not_allow') {
                            return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                        }
                    }
                }

                $final_price = $ProductVariant->final_price * $request->qty;
            } else {
                if (!empty($product)) {
                    if ($product->variant_product == 1) {
                        $products_data = ProductAttributeOption::find($request->variant_id);
                        if ($products_data) {
                            $product_stock_datas = ProductVariant::where('variant', $products_data->terms)->where('product_id', $request->product_id)->first();
                        } else {
                            $product_stock_datas = ProductVariant::find($request->variant_id);
                        }
                        if (empty($product_stock_datas)) {
                            $product_stock_datas = ProductVariant::where('id', $request->variant_id)->where('product_id', $request->product_id)->first();
                        }
                        $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;

                        $product->setAttribute('variantId', $variant_id);
                        $var_stock = !empty($product_stock_datas->stock) ? $product_stock_datas->stock : $product->product_stock;

                        if (empty($product_stock_datas->id) || $product_stock_datas->id == 0) { {
                                return $this->error(['message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                            }
                        } else if ($var_stock <= $settings['out_of_stock_threshold'] && $product->stock_order_status == 'not_allow') {
                            return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                        } else {
                            $product_stock_data = ProductVariant::find($product_stock_datas->id);
                            if ($product_stock_data && $product_stock_data->stock_status == 'out_of_stock') {
                                return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                            }
                        }
                    } else {
                        if(module_is_active('PreOrder') && isset($customer)){
                            $pre_order_detail = \Workdo\PreOrder\app\Models\PreOrder::where('store_id', $store->id)->first();
                            $pre_order_setting = \Workdo\PreOrder\app\Models\PreOrderSetting::where('product_id', $product->id)->where('status', 1)->where('store_id', $store->id)->first();
                            if(isset($pre_order_setting) && isset($pre_order_detail) && $pre_order_detail->enable_pre_order == 'on'){
                                if ($pre_order_setting->qty <= 0) {
                                    return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                }
                            }
                        }else{
                            if (($product->product_stock <= $settings['out_of_stock_threshold'] && $product->stock_order_status == 'not_allow') || ($product->track_stock == 0 && $product->stock_status == 'out_of_stock')) {
                                return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                            }
                        }
                    }
                    $final_price = floatval($product->final_price) * floatval($request->qty);
                } else {
                    return $this->error(['message' => __('Product not found.')], __('Product not found.'));
                }
            }
        }
        $variant_id = isset($product_stock_datas->id) ? $product_stock_datas->id : $request->variant_id;
        $qty = $request->qty;
        if (module_is_active('SizeGuideline') && isset($request->size_data)) {
            $cart = Cart::where( 'customer_id', $customer_id )
            ->where( 'product_id', $request->product_id )
            ->where( 'variant_id', $variant_id )
            ->where( 'size_data', $request->size_data )
            ->where( 'store_id', $store->id )
            ->first();
        }else{
            $cart = Cart::where( 'customer_id', $customer_id )
            ->where( 'product_id', $request->product_id )
            ->where( 'variant_id', $variant_id )
            ->where( 'store_id', $store->id )
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

        $cart_count = Cart::where('customer_id', $customer_id )->where( 'store_id', $store->id )->count();
        if ( empty( $cart ) ) {
            $cart = new Cart();
        } else {

            $final_price += $cart->price;
            $qty = $cart->qty + $request->qty;
        }

        $cart->customer_id = $customer_id;
        $cart->product_id = $request->product_id;
        $cart->variant_id = !empty($variant_id) ? $variant_id : 0;
        $cart->qty = $qty;
        $cart->price = $final_price;
        if (module_is_active('SizeGuideline') && isset($request->size_data)) {
            $cart->size_data = !empty( $request->size_data ) ? $request->size_data : '';
        }
        if (module_is_active('PreOrder') && isset($request->order_type)) {
            $cart->order_type = !empty( $request->order_type ) ? $request->order_type : '';
        }
       
        $cart->store_id = $store->id;
        $cart->save();

        $cart_count = Cart::where('customer_id', $customer_id)->where('store_id', $store->id)->count();
        if (!empty($cart_count)) {
            return $this->success(['message' => $product->name . ' add successfully.', 'count' => $cart_count], __($product->name . ' added successfully.'), 200, $cart_count);
        } else {
            return $this->error(['message' => __('Cart is empty.'), 'count' => $cart_count], __('Cart is empty.'));
        }
    }

    public function cart_qty(Request $request, $slug = '')
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        $rules = [
            'customer_id' => 'required',
            'product_id' => 'required',
            'variant_id' => 'required',
            'quantity_type' => 'required|in:increase,decrease,remove',
        ];
        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $customer = Customer::find($request->customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $final_price = 0;
        if (!empty($request->variant_id) || $request->variant_id != 0) {
            $ProductVariant = ProductVariant::find($request->variant_id);
            $final_price = $ProductVariant->final_price;
        } else {
            $product = Product::find($request->product_id);
            if (!empty($product)) {
                if ($product->variant_product == 1) {
                    if (empty($request->variant_id) || $request->variant_id == 0) {
                        return $this->error([
                            'message' => __('Please Select a variant in a product.')
                        ], __('Please Select a variant in a product.'));
                    }
                }
                $final_price = $product->final_price;
            }
        }
        $product = Product::find($request->product_id);
        $cart = Cart::where('customer_id', $request->customer_id)
            ->where('product_id', $request->product_id)
            ->where('variant_id', $request->variant_id)
            
            ->where('store_id', $store->id)
            ->first();
        $cart_count = Cart::where('customer_id', $request->customer_id)->where('store_id', $store->id)->count();
        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
        if (empty($cart)) {
            return $this->error(['message' => __('Your cart is empty.')], __('Your cart is empty.'), 200, 0, $cart_count);
        } else {
            if ($request->quantity_type == 'increase') {
                if (!empty($request->variant_id) || $request->variant_id != 0) {

                    if (module_is_active('CartQuantityControl')) {
                        if (isset($settings['cart_quantity_control_enable']) && $settings['cart_quantity_control_enable'] == 'on') {
                            $responseCartQuantity = \Workdo\CartQuantityControl\app\Models\CartQuantityControl::checkoutValidation($request, $ProductVariant, $cart, $settings);

                            if ($responseCartQuantity && $responseCartQuantity->getData()->status === 'error') {
                                return $this->error(['message' => $responseCartQuantity->getData()->message], 'fail', 200);
                            }
                        }
                    }

                    if ($settings['stock_management'] ?? '' == 'on') {
                        $variationOptions = explode(',', $ProductVariant->variation_option);
                        $option = in_array('manage_stock', $variationOptions);
                        if (!empty($ProductVariant)) {
                            if ($option == true) {
                                if ($cart->qty >= $ProductVariant->stock) {
                                    return Utility::error(['message' => __('can not increase product quantity.')], 'fail', 200, 0, $cart_count);
                                } else {
                                    $cart->qty += 1;
                                }
                            } else {
                                if ($ProductVariant->stock_status == 'in_stock' || $ProductVariant->stock_status == 'on_backorder') {
                                    $cart->qty += 1;
                                } elseif ($ProductVariant->stock_status == null && $option == false) {
                                    if (($product->stock_status == 'in_stock' || $product->stock_status == 'on_backorder')) {
                                        if ($product->track_stock == 1 && $cart->qty < $product->product_stock) {
                                            $cart->qty += 1;
                                        } else {

                                            $cart->qty += 1;
                                        }
                                    } elseif ($product->track_stock == 1 && $product->stock_order_status != 'not_allow') {
                                        $cart->qty += 1;
                                    } elseif ($product->track_stock == 0 && $product->stock_order_status == null &&   $product->stock_status == null) {
                                        return $this->error(['message' => __('This product is out of stock.')], __('This product is out of stock.'), 200, 0, $cart_count);
                                    } elseif ($cart->qty >= $product->product_stock) {
                                        return $this->error(['message' => __('can not increase product quantity.')], __('can not increase product quantity'), 200, 0, $cart_count);
                                    } else {
                                        $cart->qty += 1;
                                    }
                                } else {
                                    return $this->error(['message' => __('can not increase product quantity.')], __('can not increase product quantity'), 200, 0, $cart_count);
                                }
                            }
                        }
                    } else {
                        if ($ProductVariant->stock_status == 'in_stock' || $ProductVariant->stock_status == 'on_backorder') {
                            $cart->qty += 1;
                        } elseif ($ProductVariant->stock_status == null && $ProductVariant->stock != 0) {
                            $cart->qty += 1;
                        } elseif ($product->track_stock == 0 && $product->stock_order_status == null &&   $product->stock_status == 0) {
                            return $this->error(['message' => __('This product is out of stock.')], __('This product is out of stock.'), 200, 0, $cart_count);
                        } else {
                            return $this->error(['message' => __('can not increase product quantity.')], __('can not increase product quantity.'), 200, 0, $cart_count);
                        }
                    }
                } else {
                    if (module_is_active('CartQuantityControl')) {

                        if (isset($settings['cart_quantity_control_enable']) && $settings['cart_quantity_control_enable'] == 'on') {
                            $responseCartQuantity = \Workdo\CartQuantityControl\app\Models\CartQuantityControl::checkoutValidation($request, $product, $cart, $settings);

                            if ($responseCartQuantity && $responseCartQuantity->getData()->status === 'error') {
                                return $this->error(['message' => $responseCartQuantity->getData()->message], 'fail', 200);
                            }
                        }
                    }

                    if ($cart->qty >= $product->product_stock && $product->stock_order_status == 'notify_customer') {

                        $cart->price += $final_price;
                        $cart->qty += 1;
                    } elseif ($cart->qty >= $product->product_stock && $product->stock_order_status == 'out_of_stock') {
                        return $this->error(['message' => __('Can not increase product quantity beacuse product is out of stock.')], __('Can not increase product quantity.'), 200, 0, $cart_count);
                    } else {
                        $cart->price += $final_price;
                        $cart->qty += 1;
                    }
                }
            }
            if ($request->quantity_type == 'decrease') {
                if ($cart->qty == 1) {
                    return $this->error(['message' => __('Can not decrease product quantity.')], __('Can not decrease product quantity.'), 200, 0, $cart_count);
                }
                if ($cart->qty > 0) {
                    $cart->price -= $final_price;
                    $cart->qty -= 1;
                }
            }
            $cart->save();

            if ($request->quantity_type == 'remove') {
                if (auth('customers')->user()) {
                    $cart->delete();
                    return $this->success(['message' => __('Cart Product Deleted.')], __('Cart Product Deleted.'), 200, $cart_count);
                } else {
                    $cart = Cart::where('product_id', $request->product_id)->where('customer_id', $request->customer_id)->where('variant_id', $request->variant_id)->first();
                    $cart->delete();
                    return $this->success(['message' => __('Cart Product Deleted.')], __('Cart Product Deleted.'), 200, $cart_count);
                }
            }
            $cart_count = Cart::where('customer_id', $request->customer_id)->where('store_id', $store->id)->count();
            return $this->success(['message' => __('Cart successfully updated.')], __('Cart successfully updated.'), 200, $cart_count);
        }
    }

    public function wishlist(Request $request, $slug = '')
    {
        $slug = !empty($slug) ? $slug : '';
        $store =getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $rules = [
            'customer_id' => 'required',
            'product_id' => 'required',
            'wishlist_type' => 'required|in:add,remove',
        ];

        $validator = \Validator::make($request->all(), $rules, [
            'customer_id.required' => __('You must be logged in to add items to your wishlist.')
        ]);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $customer = Customer::find($request->customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('You must be logged in to add items to your wishlist.')], __('You must be logged in to add items to your wishlist.'), 401);
        }

        $Product = Product::find($request->product_id);
        if (empty($Product)) {
            return $this->error(['message' => __('Product not found.')], __('Product not found.'));
        }

        if ($request->wishlist_type == 'add') {
            $Wishlist = Wishlist::where('customer_id', $request->customer_id)->where('product_id', $request->product_id)->where('store_id', $store->id)->exists();
            if ($Wishlist) {
                return $this->error(['message' => __('Product already added in Wishlist.')], __('Product already added in Wishlist.'));
            }

            $Wishlist = new Wishlist();
            $Wishlist->customer_id = $request->customer_id;
            $Wishlist->product_id = $request->product_id;
            $Wishlist->variant_id = $request->variant_id ?? 0;
            $Wishlist->status = 1;
            $Wishlist->store_id = $store->id;
            $Wishlist->save();

            // activity log
            $ActivityLog = new ActivityLog();
            $ActivityLog->customer_id = $request->customer_id;
            $ActivityLog->log_type = 'add wishlist';
            $ActivityLog->remark = json_encode(
                ['product' => $request->product_id]
            );
            $ActivityLog->store_id = $store->id;
            $ActivityLog->save();

            $Wishlist_count = Wishlist::where('customer_id', $request->customer_id)->where('store_id', $store->id)->count();
            if (auth('customers')->user()) {

                $cart = Cart::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->count();
            } else {
                $cart = 0;
            }
            if (!empty($Wishlist_count)) {
                return $this->success(['message' => __($Product->name . ' added successfully.'), 'count' => $Wishlist_count], __($Product->name . ' added successfully.'), 200, $cart);
            } else {
                return $this->error(['message' => __('Wishlist is empty.'), 'count' => $Wishlist_count], __('Wishlist is empty.'), 200, $cart);
            }
            return $this->success(['message' => __('Added successfully to wishlist'), 'count' => 1], __('Added successfully to wishlist'), 200, $cart);
        } elseif ($request->wishlist_type == 'remove') {
            Wishlist::where('customer_id', $request->customer_id)->where('product_id', $request->product_id)->where('store_id', $store->id)->delete();

            // activity log
            $ActivityLog = new ActivityLog();
            $ActivityLog->customer_id = $request->customer_id;
            $ActivityLog->log_type = 'delete wishlist';
            $ActivityLog->remark = json_encode(
                ['product' => $request->product_id]
            );
            $ActivityLog->store_id = $store->id;
            $ActivityLog->save();

            $Wishlist_count = Wishlist::where('customer_id', $request->customer_id)->where('store_id', $store->id)->count();

            if (auth('customers')->user()) {
                $cart = Cart::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->count();
            } else {
                $cart = 0;
            }
            return $this->success(['message' => __($Product->name . ' removed successfully to wishlist.'), 'count' => $Wishlist_count], __($Product->name . ' removed successfully to wishlist.'), 200, $cart);
        } else {
            return $this->error(['message' => __('Product not found.')], __('Product not found.'));
        }
    }

    public function wishlist_list(Request $request, $slug = '')
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        $rules = [
            'customer_id' => 'required',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $customer = Customer::find($request->customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $Wishlist = Wishlist::with('ProductData')->where('customer_id', $request->customer_id)->where('store_id', $store->id)->paginate(10);
        if (auth('customers')->user()) {

            $cart = Cart::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->count();
        } else {
            $cart = 0;
        }
        if (!empty($Wishlist)) {
            return $this->success($Wishlist,  __('Wishlist get successfully.'), 200, $cart);
        } else {
            return $this->error(['message' => __('Wishlist is empty.')],  __('Wishlist is empty.'), 200, $cart);
        }
    }

    public function address_list(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $rules = [
            'customer_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $customer = Customer::find($request->customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $DeliveryAddress = DeliveryAddress::where('customer_id', $request->customer_id)->paginate(1000);

        if (!empty($DeliveryAddress)) {
            return $this->success($DeliveryAddress);
        } else {
            return $this->error(['message' => 'User not found.']);
        }
    }

    public function payment_list(Request $request, $slug = '')
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        $storage = 'storage/';
        $Setting_array = [];
        if (auth('customers')->guest()) {
            $response = Cart::cart_list_cookie($request->all(), $store->id);
            $response = json_decode(json_encode($response));
        } else {
            $api = new ApiController();
            $data = $api->cart_list($request, $store->slug);
            $response = $data->getData();
        }
        if (isset($response->data->product_list) && !empty($response->data->product_list)) {
            $product_digital_id = $product_digital_not_id = [];
            foreach ($response->data->product_list as $item) {
                $products_datas = Product::find($item->product_id);
                if ($products_datas) { // Ensure the product exists
                    if ($products_datas->product_type == 'digital') {
                        $product_digital_id[] = $products_datas->id;
                    } else {
                        $product_digital_not_id[] = $products_datas->id;
                    }
                }
            }
        } else {
            $product_digital_id = [];
            $product_digital_not_id = [1];
        }
        if (count($product_digital_id) == 0 && count($product_digital_not_id) > 0) {
            // COD
            $is_cod_enabled = Utility::GetValueByName('is_cod_enabled', $store->id);
            $cod_info = Utility::GetValueByName('cod_info', $store->id);
            $cod_image = Utility::GetValueByName('cod_image', $store->id);
            if (empty($cod_image)) {
                $cod_images = asset(Storage::url('uploads/payment/cod.png'));
            }
            $Setting_array[0]['status'] = (!empty($is_cod_enabled) && $is_cod_enabled == 'on') ? 'on' : 'off';
            $Setting_array[0]['name_string'] = 'COD';
            $Setting_array[0]['name'] = 'cod';
            if (!empty($cod_images)) {
                $Setting_array[0]['image'] = $cod_images;
            } else {
                $Setting_array[0]['image'] = $cod_image;
            }
            $Setting_array[0]['detail'] = $cod_info;
        }

        // Bank Transfer
        $bank_transfer_info = Utility::GetValueByName('bank_transfer', $store->id);
        $is_bank_transfer_enabled = Utility::GetValueByName('is_bank_transfer_enabled', $store->id);
        $bank_transfer_image = Utility::GetValueByName('bank_transfer_image', $store->id);
        if (empty($bank_transfer_image)) {
            $bank_transfer_images = asset(Storage::url('uploads/payment/bank.png'));
        }
        $Setting_array[1]['status'] = (!empty($is_bank_transfer_enabled) && $is_bank_transfer_enabled == 'on') ? 'on' : 'off';
        $Setting_array[1]['name_string'] = 'Bank Transfer';
        $Setting_array[1]['name'] = 'bank_transfer';
        if (!empty($bank_transfer_images)) {
            $Setting_array[1]['image'] = $bank_transfer_images;
        } else {
            $Setting_array[1]['image'] = $bank_transfer_image;
        }
        $Setting_array[1]['detail'] = !empty($bank_transfer_info) ? $bank_transfer_info : '';

        $Setting_array[2]['status'] = 'off';
        $Setting_array[2]['name_string'] = 'other_payment';
        $Setting_array[2]['name'] = 'Other Payment';
        $Setting_array[2]['image'] = '';
        $Setting_array[2]['detail'] = '';

        // Stripe ( Creadit card )
        $is_Stripe_enabled = Utility::GetValueByName('is_stripe_enabled', $store->id);
        $publishable_key = Utility::GetValueByName('publishable_key', $store->id);
        $stripe_secret = Utility::GetValueByName('stripe_secret', $store->id);
        $Stripe_image = Utility::GetValueByName('stripe_image', $store->id);
        if (empty($Stripe_image)) {
            $Stripe_image = asset(Storage::url('uploads/payment/stripe.png'));
        }
        $stripe_unfo = Utility::GetValueByName('stripe_unfo', $store->id);

        $Setting_array[3]['status'] = !empty($is_Stripe_enabled) ? $is_Stripe_enabled : 'off';
        $Setting_array[3]['name_string'] = 'Stripe';
        $Setting_array[3]['name'] = 'stripe';
        $Setting_array[3]['detail'] = $stripe_unfo;
        $Setting_array[3]['image'] = $Stripe_image;
        $Setting_array[3]['stripe_publishable_key'] = $publishable_key;
        $Setting_array[3]['stripe_secret_key'] = $stripe_secret;

        // Paystack
        $is_paystack_enabled = Utility::GetValueByName('is_paystack_enabled', $store->id);
        $paystack_public_key = Utility::GetValueByName('paystack_public_key', $store->id);
        $paystack_secret = Utility::GetValueByName('paystack_secret', $store->id);
        $paystack_image = Utility::GetValueByName('paystack_image', $store->id);
        if (empty($paystack_image)) {
            $paystack_image = asset(Storage::url('uploads/payment/paystack.png'));
        }
        $paystack_unfo = Utility::GetValueByName('paystack_unfo', $store->id);

        $Setting_array[4]['status'] = !empty($is_paystack_enabled) ? $is_paystack_enabled : 'off';
        $Setting_array[4]['name_string'] = 'paystack';
        $Setting_array[4]['name'] = 'paystack';
        $Setting_array[4]['detail'] = $paystack_unfo;
        $Setting_array[4]['image'] = $paystack_image;
        $Setting_array[4]['paystack_public_key'] = $paystack_public_key;
        $Setting_array[4]['paystack_secret'] = $paystack_secret;

        // Mercado Pago
        $is_mercado_enabled = Utility::GetValueByName('is_mercado_enabled', $store->id);
        $mercado_mode = Utility::GetValueByName('mercado_mode', $store->id);
        $mercado_access_token = Utility::GetValueByName('mercado_access_token', $store->id);
        $mercado_image = Utility::GetValueByName('mercado_image', $store->id);
        if (empty($mercado_image)) {
            $mercado_image = asset(Storage::url('uploads/payment/mercado.png'));
        }
        $mercado_unfo = Utility::GetValueByName('mercado_unfo', $store->id);

        $Setting_array[5]['status'] = !empty($is_mercado_enabled) ? $is_mercado_enabled : 'off';
        $Setting_array[5]['name_string'] = 'mercado';
        $Setting_array[5]['name'] = 'mercado';
        $Setting_array[5]['detail'] = $mercado_unfo;
        $Setting_array[5]['image'] = $mercado_image;
        $Setting_array[5]['mercado_mode'] = $mercado_mode;
        $Setting_array[5]['mercado_access_token'] = $mercado_access_token;

        // Skrill
        $is_skrill_enabled = Utility::GetValueByName('is_skrill_enabled', $store->id);
        $skrill_email = Utility::GetValueByName('skrill_email', $store->id);
        $skrill_image = Utility::GetValueByName('skrill_image', $store->id);
        if (empty($skrill_image)) {
            $skrill_image = asset(Storage::url('uploads/payment/skrill.png'));
        }
        $skrill_unfo = Utility::GetValueByName('skrill_unfo');

        $Setting_array[6]['status'] = !empty($is_skrill_enabled) ? $is_skrill_enabled : 'off';
        $Setting_array[6]['name_string'] = 'skrill';
        $Setting_array[6]['name'] = 'skrill';
        $Setting_array[6]['detail'] = $skrill_unfo;
        $Setting_array[6]['image'] = $skrill_image;
        $Setting_array[6]['skrill_email'] = $skrill_email;
        // PaymentWall
        $is_paymentwall_enabled = Utility::GetValueByName('is_paymentwall_enabled', $store->id);
        $paymentwall_public_key = Utility::GetValueByName('paymentwall_public_key', $store->id);
        $paymentwall_private_key = Utility::GetValueByName('paymentwall_private_key', $store->id);
        $paymentwall_image = Utility::GetValueByName('paymentwall_image', $store->id);
        if (empty($paymentwall_image)) {
            $paymentwall_image = asset(Storage::url('uploads/payment/paymentwall.png'));
        }
        $paymentwall_unfo = Utility::GetValueByName('paymentwall_unfo', $store->id);

        $Setting_array[7]['status'] = !empty($is_paymentwall_enabled) ? $is_paymentwall_enabled : 'off';
        $Setting_array[7]['name_string'] = 'paymentwall';
        $Setting_array[7]['name'] = 'paymentwall';
        $Setting_array[7]['detail'] = $paymentwall_unfo;
        $Setting_array[7]['image'] = $paymentwall_image;
        $Setting_array[7]['paymentwall_public_key'] = $paymentwall_public_key;
        $Setting_array[7]['paymentwall_private_key'] = $paymentwall_private_key;

        // Razorpay
        $is_razorpay_enabled = \App\Models\Utility::GetValueByName('is_razorpay_enabled', $store->id);
        $razorpay_public_key = \App\Models\Utility::GetValueByName('razorpay_public_key', $store->id);
        $razorpay_secret_key = \App\Models\Utility::GetValueByName('razorpay_secret_key', $store->id);
        $razorpay_image = \App\Models\Utility::GetValueByName('razorpay_image', $store->id);

        if (empty($razorpay_image)) {
            $razorpay_image = asset(Storage::url('uploads/payment/razorpay.png'));
        }
        $razorpay_unfo = Utility::GetValueByName('razorpay_unfo', $store->id);

        $Setting_array[8]['status'] = !empty($is_razorpay_enabled) ? $is_razorpay_enabled : 'off';
        $Setting_array[8]['name_string'] = 'Razorpay';
        $Setting_array[8]['name'] = 'Razorpay';
        $Setting_array[8]['detail'] = $razorpay_unfo;
        $Setting_array[8]['image'] = $razorpay_image;
        $Setting_array[8]['razorpay_public_key'] = $razorpay_public_key;
        $Setting_array[8]['razorpay_secret_key'] = $razorpay_secret_key;

        //paypal
        $is_paypal_enabled = Utility::GetValueByName('is_paypal_enabled', $store->id);
        $paypal_secret = Utility::GetValueByName('paypal_secret', $store->id);
        $paypal_client_id = Utility::GetValueByName('paypal_client_id', $store->id);
        $paypal_mode = Utility::GetValueByName('paypal_mode', $store->id);
        $paypal_description = Utility::GetValueByName('paypal_unfo', $store->id);
        $paypal_image = Utility::GetValueByName('paypal_image', $store->id);

        if (empty($paypal_image)) {
            $paypal_image = asset(Storage::url('uploads/payment/paypal.png'));
        }

        $Setting_array[9]['status'] = !empty($is_paypal_enabled) ? $is_paypal_enabled : 'off';
        $Setting_array[9]['name_string'] = 'Paypal';
        $Setting_array[9]['name'] = 'paypal';
        $Setting_array[9]['detail'] = $paypal_description;
        $Setting_array[9]['image'] = $paypal_image;
        $Setting_array[9]['paypal_secret'] = $paypal_secret;
        $Setting_array[9]['paypal_client_id'] = $paypal_client_id;
        $Setting_array[9]['paypal_mode'] = $paypal_mode;

        //flutterwave
        $is_flutterwave_enabled = \App\Models\Utility::GetValueByName('is_flutterwave_enabled', $store->id);
        $public_key = \App\Models\Utility::GetValueByName('public_key', $store->id);
        $flutterwave_secret = \App\Models\Utility::GetValueByName('flutterwave_secret', $store->id);
        $flutterwave_description = Utility::GetValueByName('flutterwave_unfo', $store->id);
        $flutterwave_image = \App\Models\Utility::GetValueByName('flutterwave_image', $store->id);

        if (empty($flutterwave_image)) {
            $flutterwave_image = asset(Storage::url('uploads/payment/flutterwave.png'));
        }

        $Setting_array[10]['status'] = !empty($is_flutterwave_enabled) ? $is_flutterwave_enabled : 'off';
        $Setting_array[10]['name_string'] = 'Flutterwave';
        $Setting_array[10]['name'] = 'flutterwave';
        $Setting_array[10]['detail'] = $flutterwave_description;
        $Setting_array[10]['image'] = $flutterwave_image;
        $Setting_array[10]['public_key'] = $public_key;
        $Setting_array[10]['flutterwave_secret'] = $flutterwave_secret;
        $Setting_array[10]['flutterwave_image'] = $flutterwave_image;

        //paytm
        $is_paytm_enabled = Utility::GetValueByName('is_paytm_enabled', $store->id);
        $paytm_merchant_id = Utility::GetValueByName('paytm_merchant_id', $store->id);
        $paytm_merchant_key = Utility::GetValueByName('paytm_merchant_key', $store->id);
        $paytm_industry_type = Utility::GetValueByName('paytm_industry_type', $store->id);
        $paytm_mode = Utility::GetValueByName('paytm_mode', $store->id);
        $payptm_description = Utility::GetValueByName('paytm_unfo', $store->id);
        $paytm_image = Utility::GetValueByName('paytm_image', $store->id);

        if (empty($paytm_image)) {
            $paytm_image = asset(Storage::url('uploads/payment/paytm.png'));
        }

        $Setting_array[11]['status'] = !empty($is_paytm_enabled) ? $is_paytm_enabled : 'off';
        $Setting_array[11]['name_string'] = 'Paytm';
        $Setting_array[11]['name'] = 'paytm';
        $Setting_array[11]['detail'] = $payptm_description;
        $Setting_array[11]['image'] = $paytm_image;
        $Setting_array[11]['paytm_merchant_id'] = $paytm_merchant_id;
        $Setting_array[11]['paytm_merchant_key'] = $paytm_merchant_key;
        $Setting_array[11]['paytm_industry_type'] = $paytm_industry_type;
        $Setting_array[11]['paytm_mode'] = $paytm_mode;

        //mollie
        $is_mollie_enabled = Utility::GetValueByName('is_mollie_enabled', $store->id);
        $mollie_api_key = Utility::GetValueByName('mollie_api_key', $store->id);
        $mollie_profile_id = Utility::GetValueByName('mollie_profile_id', $store->id);
        $mollie_partner_id = Utility::GetValueByName('mollie_partner_id', $store->id);
        $mollie_unfo = Utility::GetValueByName('mollie_unfo', $store->id);
        $mollie_image = Utility::GetValueByName('mollie_image', $store->id);

        if (empty($mollie_image)) {
            $mollie_image = asset(Storage::url('uploads/payment/mollie.png'));
        }

        $Setting_array[12]['status'] = !empty($is_mollie_enabled) ? $is_mollie_enabled : 'off';
        $Setting_array[12]['name_string'] = 'mollie';
        $Setting_array[12]['name'] = 'mollie';
        $Setting_array[12]['detail'] = $mollie_unfo;
        $Setting_array[12]['image'] = $mollie_image;
        $Setting_array[12]['mollie_api_key'] = $mollie_api_key;
        $Setting_array[12]['mollie_profile_id'] = $mollie_profile_id;
        $Setting_array[12]['mollie_partner_id'] = $mollie_partner_id;

        //coingate
        $is_coingate_enabled = Utility::GetValueByName('is_coingate_enabled', $store->id);
        $coingate_mode = Utility::GetValueByName('coingate_mode', $store->id);
        $coingate_auth_token = Utility::GetValueByName('coingate_auth_token', $store->id);
        $coingate_image = Utility::GetValueByName('coingate_image', $store->id);
        $coingate_unfo = Utility::GetValueByName('coingate_unfo', $store->id);

        if (empty($coingate_image)) {
            $coingate_image = asset(Storage::url('uploads/payment/coingate.png'));
        }

        $Setting_array[13]['status'] = !empty($is_coingate_enabled) ? $is_coingate_enabled : 'off';
        $Setting_array[13]['name_string'] = 'coingate';
        $Setting_array[13]['name'] = 'coingate';
        $Setting_array[13]['detail'] = $coingate_unfo;
        $Setting_array[13]['image'] = $coingate_image;
        $Setting_array[13]['coingate_mode'] = $coingate_mode;
        $Setting_array[13]['coingate_auth_token'] = $coingate_auth_token;

        //sspay
        $is_sspay_enabled = Utility::GetValueByName('is_sspay_enabled', $store->id);
        $categoryCode = Utility::GetValueByName('sspay_category_code', $store->id);
        $secretKey = Utility::GetValueByName('is_sspay_enabled', $store->id);
        $sspay_image = Utility::GetValueByName('sspay_image', $store->id);
        $sspay_unfo = Utility::GetValueByName('sspay_unfo', $store->id);

        if (empty($sspay_image)) {
            $sspay_image = asset(Storage::url('uploads/payment/sspay.png'));
        }

        $Setting_array[14]['status'] = !empty($is_sspay_enabled) ? $is_sspay_enabled : 'off';
        $Setting_array[14]['name_string'] = 'Sspay';
        $Setting_array[14]['name'] = 'Sspay';
        $Setting_array[14]['detail'] = $sspay_unfo;
        $Setting_array[14]['image'] = $sspay_image;
        $Setting_array[14]['categoryCode'] = $categoryCode;
        $Setting_array[14]['secretKey'] = $secretKey;

        //toyyibpay
        $is_toyyibpay_enabled = Utility::GetValueByName('is_toyyibpay_enabled', $store->id);
        $categoryCode = Utility::GetValueByName('toyyibpay_category_code', $store->id);
        $secretKey = Utility::GetValueByName('is_toyyibpay_enabled', $store->id);
        $toyyibpay_image = Utility::GetValueByName('toyyibpay_image', $store->id);
        $toyyibpay_unfo = Utility::GetValueByName('toyyibpay_unfo', $store->id);

        if (empty($toyyibpay_image)) {
            $toyyibpay_image = asset(Storage::url('uploads/payment/toyyibpay.png'));
        }

        $Setting_array[15]['status'] = !empty($is_toyyibpay_enabled) ? $is_toyyibpay_enabled : 'off';
        $Setting_array[15]['name_string'] = 'toyyibpay';
        $Setting_array[15]['name'] = 'toyyibpay';
        $Setting_array[15]['detail'] = $toyyibpay_unfo;
        $Setting_array[15]['image'] = $toyyibpay_image;
        $Setting_array[15]['categoryCode'] = $categoryCode;
        $Setting_array[15]['secretKey'] = $secretKey;

        //paytabs
        $is_paytabs_enabled = Utility::GetValueByName('is_paytabs_enabled', $store->id);
        $Profile_id = Utility::GetValueByName('paytabs_profile_id', $store->id);
        $Serverkey = Utility::GetValueByName('paytabs_server_key', $store->id);
        $Region = Utility::GetValueByName('paytabs_region', $store->id);
        $paytabs_image = Utility::GetValueByName('paytabs_image', $store->id);
        $paytabs_unfo = Utility::GetValueByName('paytabs_unfo', $store->id);

        if (empty($paytabs_image)) {
            $paytabs_image = asset(Storage::url('uploads/payment/paytabs.png'));
        }

        $Setting_array[16]['status'] = !empty($is_paytabs_enabled) ? $is_paytabs_enabled : 'off';
        $Setting_array[16]['name_string'] = 'Paytabs';
        $Setting_array[16]['name'] = 'Paytabs';
        $Setting_array[16]['detail'] = $paytabs_unfo;
        $Setting_array[16]['image'] = $paytabs_image;
        $Setting_array[16]['paytabs_profile_id'] = $Profile_id;
        $Setting_array[16]['paytabs_server_key'] = $Serverkey;
        $Setting_array[16]['paytabs_region'] = $Region;

        //Iyzipay
        $is_iyzipay_enabled = Utility::GetValueByName('is_iyzipay_enabled', $store->id);
        $iyzipay_mode = Utility::GetValueByName('iyzipay_mode', $store->id);
        $iyzipay_secret_key = Utility::GetValueByName('iyzipay_secret_key', $store->id);
        $iyzipay_private_key = Utility::GetValueByName('iyzipay_private_key', $store->id);
        $iyzipay_image = Utility::GetValueByName('iyzipay_image', $store->id);
        $iyzipay_unfo = Utility::GetValueByName('iyzipay_unfo', $store->id);

        if (empty($iyzipay_image)) {
            $iyzipay_image = asset(Storage::url('uploads/payment/iyzipay.png'));
        }

        $Setting_array[17]['status'] = !empty($is_iyzipay_enabled) ? $is_iyzipay_enabled : 'off';
        $Setting_array[17]['name_string'] = 'iyzipay';
        $Setting_array[17]['name'] = 'iyzipay';
        $Setting_array[17]['detail'] = $iyzipay_unfo;
        $Setting_array[17]['image'] = $iyzipay_image;
        $Setting_array[17]['iyzipay_mode'] = $iyzipay_mode;
        $Setting_array[17]['iyzipay_secret_key'] = $iyzipay_secret_key;
        $Setting_array[17]['iyzipay_private_key'] = $iyzipay_private_key;

        //payfast
        $is_payfast_enabled = Utility::GetValueByName('is_payfast_enabled', $store->id);
        $payfast_mode = Utility::GetValueByName('payfast_mode', $store->id);
        $payfast_merchant_id = Utility::GetValueByName('payfast_merchant_id', $store->id);
        $payfast_salt_passphrase = Utility::GetValueByName('payfast_salt_passphrase', $store->id);
        $payfast_merchant_key = Utility::GetValueByName('payfast_merchant_key', $store->id);
        $payfast_image = Utility::GetValueByName('payfast_image', $store->id);
        $payfast_unfo = Utility::GetValueByName('payfast_unfo', $store->id);

        if (empty($payfast_image)) {
            $payfast_image = asset(Storage::url('uploads/payment/payfast.png'));
        }

        $Setting_array[18]['status'] = !empty($is_payfast_enabled) ? $is_payfast_enabled : 'off';
        $Setting_array[18]['name_string'] = 'payfast';
        $Setting_array[18]['name'] = 'payfast';
        $Setting_array[18]['detail'] = $payfast_unfo;
        $Setting_array[18]['image'] = $payfast_image;
        $Setting_array[18]['payfast_mode'] = $payfast_mode;
        $Setting_array[18]['payfast_merchant_id'] = $payfast_merchant_id;
        $Setting_array[18]['payfast_salt_passphrase'] = $payfast_salt_passphrase;
        $Setting_array[18]['payfast_merchant_key'] = $payfast_merchant_key;

        //Benefit
        $is_benefit_enabled = Utility::GetValueByName('is_benefit_enabled', $store->id);
        $benefit_mode = Utility::GetValueByName('benefit_mode', $store->id);
        $benefit_secret_key = Utility::GetValueByName('benefit_secret_key', $store->id);
        $benefit_private_key = Utility::GetValueByName('benefit_private_key', $store->id);
        $benefit_image = Utility::GetValueByName('benefit_image', $store->id);
        $benefit_unfo = Utility::GetValueByName('benefit_unfo', $store->id);

        if (empty($benefit_image)) {
            $benefit_image = asset(Storage::url('uploads/payment/benefit.png'));
        }

        $Setting_array[19]['status'] = !empty($is_benefit_enabled) ? $is_benefit_enabled : 'off';
        $Setting_array[19]['name_string'] = 'benefit';
        $Setting_array[19]['name'] = 'benefit';
        $Setting_array[19]['detail'] = $benefit_unfo;
        $Setting_array[19]['image'] = $benefit_image;
        $Setting_array[19]['benefit_mode'] = $benefit_mode;
        $Setting_array[19]['benefit_secret_key'] = $benefit_secret_key;
        $Setting_array[19]['benefit_private_key'] = $benefit_private_key;

        //Cashfree
        $is_cashfree_enabled = Utility::GetValueByName('is_cashfree_enabled', $store->id);
        $cashfree_secret_key = Utility::GetValueByName('cashfree_secret_key', $store->id);
        $cashfree_key = Utility::GetValueByName('cashfree_key', $store->id);
        $cashfree_image = Utility::GetValueByName('cashfree_image', $store->id);
        $cashfree_unfo = Utility::GetValueByName('cashfree_unfo', $store->id);

        if (empty($cashfree_image)) {
            $cashfree_image = asset(Storage::url('uploads/payment/cashfree.png'));
        }

        $Setting_array[20]['status'] = !empty($is_cashfree_enabled) ? $is_cashfree_enabled : 'off';
        $Setting_array[20]['name_string'] = 'cashfree';
        $Setting_array[20]['name'] = 'cashfree';
        $Setting_array[20]['detail'] = $cashfree_unfo;
        $Setting_array[20]['image'] = $cashfree_image;
        $Setting_array[20]['cashfree_secret_key'] = $cashfree_secret_key;
        $Setting_array[20]['cashfree_key'] = $cashfree_key;

        //Aamarpay
        $is_aamarpay_enabled = Utility::GetValueByName('is_aamarpay_enabled', $store->id);
        $aamarpay_signature_key = Utility::GetValueByName('aamarpay_signature_key', $store->id);
        $aamarpay_description = Utility::GetValueByName('aamarpay_description', $store->id);
        $aamarpay_store_id = Utility::GetValueByName('aamarpay_store_id', $store->id);
        $aamarpay_image = Utility::GetValueByName('aamarpay_image', $store->id);
        $aamarpay_unfo = Utility::GetValueByName('aamarpay_unfo', $store->id);

        if (empty($aamarpay_image)) {
            $aamarpay_image = asset(Storage::url('uploads/payment/aamarpay.png'));
        }

        $Setting_array[21]['status'] = !empty($is_aamarpay_enabled) ? $is_aamarpay_enabled : 'off';
        $Setting_array[21]['name_string'] = 'aamarpay';
        $Setting_array[21]['name'] = 'aamarpay';
        $Setting_array[21]['detail'] = $aamarpay_unfo;
        $Setting_array[21]['image'] = $aamarpay_image;
        $Setting_array[21]['aamarpay_signature_key'] = $aamarpay_signature_key;
        $Setting_array[21]['aamarpay_description'] = $aamarpay_description;
        $Setting_array[21]['aamarpay_store_id'] = $aamarpay_store_id;

        //Telegram
        $is_telegram_enabled = Utility::GetValueByName('is_telegram_enabled', $store->id);
        $telegram_access_token = Utility::GetValueByName('telegram_access_token', $store->id);
        $telegram_chat_id = Utility::GetValueByName('telegram_chat_id', $store->id);
        $telegram_image = Utility::GetValueByName('telegram_image', $store->id);
        $telegram_unfo = Utility::GetValueByName('telegram_unfo', $store->id);

        if (empty($telegram_image)) {
            $telegram_image = asset(Storage::url('uploads/payment/telegram.png'));
        }

        $Setting_array[22]['status'] = !empty($is_telegram_enabled) ? $is_telegram_enabled : 'off';
        $Setting_array[22]['name_string'] = 'telegram';
        $Setting_array[22]['name'] = 'telegram';
        $Setting_array[22]['detail'] = $telegram_unfo;
        $Setting_array[22]['image'] = $telegram_image;
        $Setting_array[22]['telegram_access_token'] = $telegram_access_token;
        $Setting_array[22]['telegram_chat_id'] = $telegram_chat_id;

        //Whatsapp
        $is_whatsapp_enabled = Utility::GetValueByName('is_whatsapp_enabled', $store->id);
        $whatsapp_number = Utility::GetValueByName('whatsapp_number', $store->id);
        $whatsapp_image = Utility::GetValueByName('whatsapp_image', $store->id);
        $whatsapp_unfo = Utility::GetValueByName('whatsapp_unfo', $store->id);

        if (empty($whatsapp_image)) {
            $whatsapp_image = asset(Storage::url('uploads/payment/whatsapp.png'));
        }

        $Setting_array[23]['status'] = !empty($is_whatsapp_enabled) ? $is_whatsapp_enabled : 'off';
        $Setting_array[23]['name_string'] = 'whatsapp';
        $Setting_array[23]['name'] = 'whatsapp';
        $Setting_array[23]['detail'] = $whatsapp_unfo;
        $Setting_array[23]['image'] = $whatsapp_image;
        $Setting_array[23]['whatsapp_number'] = $whatsapp_number;

        //Pay TR
        $is_paytr_enabled = Utility::GetValueByName('is_paytr_enabled', $store->id);
        $paytr_merchant_id = Utility::GetValueByName('paytr_merchant_id', $store->id);
        $paytr_merchant_key = Utility::GetValueByName('paytr_merchant_key', $store->id);
        $paytr_salt_key = Utility::GetValueByName('paytr_salt_key', $store->id);
        $paytr_image = Utility::GetValueByName('paytr_image', $store->id);
        $paytr_unfo = Utility::GetValueByName('paytr_unfo', $store->id);

        if (empty($paytr_image)) {
            $paytr_image = asset(Storage::url('uploads/payment/paytr.png'));
        }

        $Setting_array[24]['status'] = !empty($is_paytr_enabled) ? $is_paytr_enabled : 'off';
        $Setting_array[24]['name_string'] = 'paytr';
        $Setting_array[24]['name'] = 'paytr';
        $Setting_array[24]['detail'] = $paytr_unfo;
        $Setting_array[24]['image'] = $paytr_image;
        $Setting_array[24]['paytr_merchant_id'] = $paytr_merchant_id;
        $Setting_array[24]['paytr_merchant_key'] = $paytr_merchant_key;
        $Setting_array[24]['paytr_salt_key'] = $paytr_salt_key;

        //Yookassa
        $is_yookassa_enabled = Utility::GetValueByName('is_yookassa_enabled', $store->id);
        $yookassa_shop_id_key = Utility::GetValueByName('yookassa_shop_id_key', $store->id);
        $yookassa_secret_key = Utility::GetValueByName('yookassa_secret_key', $store->id);
        $yookassa_image = Utility::GetValueByName('yookassa_image', $store->id);
        $yookassa_unfo = Utility::GetValueByName('yookassa_unfo', $store->id);

        if (empty($yookassa_image)) {
            $yookassa_image = asset(Storage::url('uploads/payment/yookassa.png'));
        }

        // RETIRED (RetiredGateways::PROVIDERS): published 'off' whatever the tenant's row says. The index,
        // the key set and every credential key below are UNCHANGED: repo B parses this shape.
        $Setting_array[25]['status'] = RetiredGateways::status('yookassa', $is_yookassa_enabled);
        $Setting_array[25]['name_string'] = 'yookassa';
        $Setting_array[25]['name'] = 'yookassa';
        $Setting_array[25]['detail'] = $yookassa_unfo;
        $Setting_array[25]['image'] = $yookassa_image;
        $Setting_array[25]['yookassa_shop_id_key'] = $yookassa_shop_id_key;
        $Setting_array[25]['yookassa_secret_key'] = $yookassa_secret_key;

        //Xendit
        $is_Xendit_enabled = Utility::GetValueByName('is_Xendit_enabled', $store->id);
        $Xendit_api_key = Utility::GetValueByName('Xendit_api_key', $store->id);
        $Xendit_token_key = Utility::GetValueByName('Xendit_token_key', $store->id);
        $Xendit_image = Utility::GetValueByName('Xendit_image', $store->id);
        $Xendit_unfo = Utility::GetValueByName('Xendit_unfo', $store->id);

        if (empty($Xendit_image)) {
            $Xendit_image = asset(Storage::url('uploads/payment/xendit.png'));
        }
        $Setting_array[26]['status'] = !empty($is_Xendit_enabled) ? $is_Xendit_enabled : 'off';
        $Setting_array[26]['name_string'] = 'Xendit';
        $Setting_array[26]['name'] = 'Xendit';
        $Setting_array[26]['detail'] = $Xendit_unfo;
        $Setting_array[26]['image'] = $Xendit_image;
        $Setting_array[26]['Xendit_api_key'] = $Xendit_api_key;
        $Setting_array[26]['Xendit_token_key'] = $Xendit_token_key;

        //Midtrans
        $is_midtrans_enabled = Utility::GetValueByName('is_midtrans_enabled', $store->id);
        $midtrans_secret_key = Utility::GetValueByName('midtrans_secret_key', $store->id);
        $midtrans_image = Utility::GetValueByName('midtrans_image', $store->id);
        $midtrans_unfo = Utility::GetValueByName('midtrans_unfo', $store->id);

        if (empty($midtrans_image)) {
            $midtrans_image = asset(Storage::url('uploads/payment/midtrans.png'));
        }

        $Setting_array[27]['status'] = !empty($is_midtrans_enabled) ? $is_midtrans_enabled : 'off';
        $Setting_array[27]['name_string'] = 'midtrans';
        $Setting_array[27]['name'] = 'midtrans';
        $Setting_array[27]['detail'] = $midtrans_unfo;
        $Setting_array[27]['image'] = $midtrans_image;
        $Setting_array[27]['midtrans_secret_key'] = $midtrans_secret_key;

        //Nepalste
        $is_nepalste_enabled = Utility::GetValueByName('is_nepalste_enabled', $store->id);
        $nepalste_secret_key = Utility::GetValueByName('nepalste_secret_key', $store->id);
        $nepalste_public_key = Utility::GetValueByName('nepalste_public_key', $store->id);
        $nepalste_image = Utility::GetValueByName('nepalste_image', $store->id);
        $nepalste_unfo = Utility::GetValueByName('nepalste_unfo', $store->id);

        if (empty($nepalste_image)) {
            $nepalste_image = asset(Storage::url('uploads/payment/nepalste.png'));
        }

        $Setting_array[28]['status'] = !empty($is_nepalste_enabled) ? $is_nepalste_enabled : 'off';
        $Setting_array[28]['name_string'] = 'nepalste';
        $Setting_array[28]['name'] = 'Nepalste';
        $Setting_array[28]['detail'] = $nepalste_unfo;
        $Setting_array[28]['image'] = $nepalste_image;
        $Setting_array[28]['nepalste_secret_key'] = $nepalste_secret_key;
        $Setting_array[28]['nepalste_public_key'] = $nepalste_public_key;

        //Khalti
        $is_khalti_enabled = Utility::GetValueByName('is_khalti_enabled', $store->id);
        $khalti_secret_key = Utility::GetValueByName('khalti_secret_key', $store->id);
        $khalti_public_key = Utility::GetValueByName('khalti_public_key', $store->id);
        $khalti_image = Utility::GetValueByName('khalti_image', $store->id);
        $khalti_unfo = Utility::GetValueByName('khalti_unfo', $store->id);

        if (empty($khalti_image)) {
            $khalti_image = asset(Storage::url('uploads/payment/khalti.png'));
        }

        $Setting_array[29]['status'] = !empty($is_khalti_enabled) ? $is_khalti_enabled : 'off';
        $Setting_array[29]['name_string'] = 'Khalti';
        $Setting_array[29]['name'] = 'khalti';
        $Setting_array[29]['detail'] = $khalti_unfo;
        $Setting_array[29]['image'] = $khalti_image;
        $Setting_array[29]['khalti_secret_key'] = $khalti_secret_key;
        $Setting_array[29]['khalti_public_key'] = $khalti_public_key;

        //PayHere
        $is_payhere_enabled = Utility::GetValueByName('is_payhere_enabled', $store->id);
        $payhere_mode = Utility::GetValueByName('payhere_mode', $store->id);
        $payhere_merchant_id = Utility::GetValueByName('payhere_merchant_id', $store->id);
        $payhere_merchant_secret = Utility::GetValueByName('payhere_merchant_secret', $store->id);
        $payhere_app_id = Utility::GetValueByName('payhere_app_id', $store->id);
        $payhere_app_secret = Utility::GetValueByName('payhere_app_secret', $store->id);
        $payhere_image = Utility::GetValueByName('payhere_image', $store->id);
        $payhere_unfo = Utility::GetValueByName('payhere_unfo', $store->id);

        if (empty($payhere_image)) {
            $payhere_image = asset(Storage::url('uploads/payment/payhere.png'));
        }

        // RETIRED (RetiredGateways::PROVIDERS): published 'off' whatever the tenant's row says. The index,
        // the key set and every credential key below are UNCHANGED: repo B parses this shape.
        $Setting_array[30]['status'] = RetiredGateways::status('payhere', $is_payhere_enabled);
        $Setting_array[30]['name_string'] = 'payhere';
        $Setting_array[30]['name'] = 'PayHere';
        $Setting_array[30]['detail'] = $payhere_unfo;
        $Setting_array[30]['image'] = $payhere_image;
        $Setting_array[30]['payhere_mode'] = $payhere_mode;
        $Setting_array[30]['payhere_merchant_id'] = $payhere_merchant_id;
        $Setting_array[30]['payhere_merchant_secret'] = $payhere_merchant_secret;
        $Setting_array[30]['payhere_app_id'] = $payhere_app_id;
        $Setting_array[30]['payhere_app_secret'] = $payhere_app_secret;

        //AuthorizeNet
        $is_authorizenet_enabled = Utility::GetValueByName('is_authorizenet_enabled', $store->id);
        $authorizenet_mode = Utility::GetValueByName('authorizenet_mode', $store->id);
        $authorizenet_login_id = Utility::GetValueByName('authorizenet_login_id', $store->id);
        $authorizenet_transaction_key = Utility::GetValueByName('authorizenet_transaction_key', $store->id);
        $authorizenet_image = Utility::GetValueByName('authorizenet_image', $store->id);
        $authorizenet_unfo = Utility::GetValueByName('authorizenet_unfo', $store->id);

        if (empty($authorizenet_image)) {
            $authorizenet_image = asset(Storage::url('uploads/payment/authorizenet.png'));
        }

        $Setting_array[31]['status'] = !empty($is_authorizenet_enabled) ? $is_authorizenet_enabled : 'off';
        $Setting_array[31]['name_string'] = 'authorizenet';
        $Setting_array[31]['name'] = 'AuthorizeNet';
        $Setting_array[31]['detail'] = $authorizenet_unfo;
        $Setting_array[31]['image'] = $authorizenet_image;
        $Setting_array[31]['authorizenet_mode'] = $authorizenet_mode;
        $Setting_array[31]['authorizenet_login_id'] = $authorizenet_login_id;
        $Setting_array[31]['authorizenet_transaction_key'] = $authorizenet_transaction_key;

        //Tap
        $is_tap_enabled = Utility::GetValueByName('is_tap_enabled', $store->id);
        $tap_secret_key = Utility::GetValueByName('tap_secret_key', $store->id);
        $tap_image = Utility::GetValueByName('tap_image', $store->id);
        $tap_unfo = Utility::GetValueByName('tap_unfo', $store->id);

        if (empty($tap_image)) {
            $tap_image = asset(Storage::url('uploads/payment/tap.png'));
        }

        $Setting_array[32]['status'] = !empty($is_tap_enabled) ? $is_tap_enabled : 'off';
        $Setting_array[32]['name_string'] = 'tap';
        $Setting_array[32]['name'] = 'Tap';
        $Setting_array[32]['detail'] = $tap_unfo;
        $Setting_array[32]['image'] = $tap_image;
        $Setting_array[32]['tap_secret_key'] = $tap_secret_key;

        //PhonePe
        $is_phonepe_enabled = Utility::GetValueByName('is_phonepe_enabled', $store->id);
        $phonepe_mode = Utility::GetValueByName('phonepe_mode', $store->id);
        $phonepe_image = Utility::GetValueByName('phonepe_image', $store->id);
        $phonepe_unfo = Utility::GetValueByName('phonepe_unfo', $store->id);
        $phonepe_client_id = Utility::GetValueByName('phonepe_client_id', $store->id);
        $phonepe_client_secret = Utility::GetValueByName('phonepe_client_secret', $store->id);
        $phonepe_client_version = Utility::GetValueByName('phonepe_client_version', $store->id);

        if (empty($phonepe_image)) {
            $phonepe_image = asset(Storage::url('uploads/payment/phonepe.png'));
        }

        $Setting_array[33]['status'] = !empty($is_phonepe_enabled) ? $is_phonepe_enabled : 'off';
        $Setting_array[33]['name_string'] = 'phonepe';
        $Setting_array[33]['name'] = 'PhonePe';
        $Setting_array[33]['detail'] = $phonepe_unfo;
        $Setting_array[33]['image'] = $phonepe_image;
        $Setting_array[33]['phonepe_mode'] = $phonepe_mode;
        $Setting_array[33]['phonepe_client_id'] = $phonepe_client_id;
        $Setting_array[33]['phonepe_client_secret'] = $phonepe_client_secret;
        $Setting_array[33]['phonepe_client_version'] = $phonepe_client_version;

        //Paddle
        $is_paddle_enabled = Utility::GetValueByName('is_paddle_enabled', $store->id);
        $paddle_mode = Utility::GetValueByName('paddle_mode', $store->id);
        $paddle_image = Utility::GetValueByName('paddle_image', $store->id);
        $paddle_unfo = Utility::GetValueByName('paddle_unfo', $store->id);
        $paddle_vendor_id = Utility::GetValueByName('paddle_vendor_id', $store->id);
        $paddle_vendor_auth_code = Utility::GetValueByName('paddle_vendor_auth_code', $store->id);
        $paddle_public_key = Utility::GetValueByName('paddle_public_key', $store->id);

        if (empty($paddle_image)) {
            $paddle_image = asset(Storage::url('uploads/payment/paddle.png'));
        }

        $Setting_array[34]['status'] = !empty($is_paddle_enabled) ? $is_paddle_enabled : 'off';
        $Setting_array[34]['name_string'] = 'paddle';
        $Setting_array[34]['name'] = 'Paddle';
        $Setting_array[34]['detail'] = $paddle_unfo;
        $Setting_array[34]['image'] = $paddle_image;
        $Setting_array[34]['paddle_mode'] = $paddle_mode;
        $Setting_array[34]['paddle_vendor_id'] = $paddle_vendor_id;
        $Setting_array[34]['paddle_vendor_auth_code'] = $paddle_vendor_auth_code;
        $Setting_array[34]['paddle_public_key'] = $paddle_public_key;

        //Paiementpro
        $is_paiementpro_enabled = Utility::GetValueByName('is_paiementpro_enabled', $store->id);
        $paiementpro_image = Utility::GetValueByName('paiementpro_image', $store->id);
        $paiementpro_unfo = Utility::GetValueByName('paiementpro_unfo', $store->id);
        $paiementpro_merchant_id = Utility::GetValueByName('paiementpro_merchant_id', $store->id);

        if (empty($paiementpro_image)) {
            $paiementpro_image = asset(Storage::url('uploads/payment/paiementpro.png'));
        }

        $Setting_array[35]['status'] = !empty($is_paiementpro_enabled) ? $is_paiementpro_enabled : 'off';
        $Setting_array[35]['name_string'] = 'paiementpro';
        $Setting_array[35]['name'] = 'Paiementpro';
        $Setting_array[35]['detail'] = $paiementpro_unfo;
        $Setting_array[35]['image'] = $paiementpro_image;
        $Setting_array[35]['paiementpro_merchant_id'] = $paiementpro_merchant_id;

        //FedPay
        $is_fedpay_enabled = Utility::GetValueByName('is_fedpay_enabled', $store->id);
        $fedpay_image = Utility::GetValueByName('fedpay_image', $store->id);
        $fedpay_unfo = Utility::GetValueByName('fedpay_unfo', $store->id);
        $fedpay_secret_key = Utility::GetValueByName('fedpay_secret_key', $store->id);
        $fedpay_public_key = Utility::GetValueByName('fedpay_public_key', $store->id);

        if (empty($fedpay_image)) {
            $fedpay_image = asset(Storage::url('uploads/payment/fedpay.png'));
        }

        $Setting_array[36]['status'] = !empty($is_fedpay_enabled) ? $is_fedpay_enabled : 'off';
        $Setting_array[36]['name_string'] = 'fedpay';
        $Setting_array[36]['name'] = 'FedPay';
        $Setting_array[36]['detail'] = $fedpay_unfo;
        $Setting_array[36]['image'] = $fedpay_image;
        $Setting_array[36]['fedpay_public_key'] = $fedpay_public_key;
        $Setting_array[36]['fedpay_secret_key'] = $fedpay_secret_key;

        //CinetPay
        $is_cinetpay_enabled = Utility::GetValueByName('is_cinetpay_enabled', $store->id);
        $cinet_pay_image = Utility::GetValueByName('cinet_pay_image', $store->id);
        $cinet_pay_unfo = Utility::GetValueByName('cinet_pay_unfo', $store->id);
        $cinet_pay_site_id = Utility::GetValueByName('cinet_pay_site_id', $store->id);
        $cinet_pay_api_key = Utility::GetValueByName('cinet_pay_api_key', $store->id);

        if (empty($cinet_pay_image)) {
            $cinet_pay_image = asset(Storage::url('uploads/payment/cinet.png'));
        }

        $Setting_array[37]['status'] = !empty($is_cinetpay_enabled) ? $is_cinetpay_enabled : 'off';
        $Setting_array[37]['name_string'] = 'cinetpay';
        $Setting_array[37]['name'] = 'CinetPay';
        $Setting_array[37]['detail'] = $cinet_pay_unfo;
        $Setting_array[37]['image'] = $cinet_pay_image;
        $Setting_array[37]['cinet_pay_site_id'] = $cinet_pay_site_id;
        $Setting_array[37]['cinet_pay_api_key'] = $cinet_pay_api_key;

        //senagepay
        $is_Senangpay_enabled = Utility::GetValueByName('is_Senangpay_enabled', $store->id);
        $senang_pay_image = Utility::GetValueByName('senang_pay_image', $store->id);
        $senang_pay_unfo = Utility::GetValueByName('senang_pay_unfo', $store->id);
        $Senangpay_mode = Utility::GetValueByName('Senangpay_mode', $store->id);
        $senang_pay_merchant_id = Utility::GetValueByName('senang_pay_merchant_id', $store->id);
        $senang_pay_secret_key = Utility::GetValueByName('senang_pay_secret_key', $store->id);

        if (empty($senang_pay_image)) {
            $senang_pay_image = asset(Storage::url('uploads/payment/senang.png'));
        }

        $Setting_array[38]['status'] = !empty($is_Senangpay_enabled) ? $is_Senangpay_enabled : 'off';
        $Setting_array[38]['name_string'] = 'senagepay';
        $Setting_array[38]['name'] = 'SenagePay';
        $Setting_array[38]['detail'] = $senang_pay_unfo;
        $Setting_array[38]['image'] = $senang_pay_image;
        $Setting_array[38]['Senangpay_mode'] = $Senangpay_mode;
        $Setting_array[38]['senang_pay_merchant_id'] = $senang_pay_merchant_id;
        $Setting_array[38]['senang_pay_secret_key'] = $senang_pay_secret_key;

        //cybersource
        $is_cybersource_enabled = Utility::GetValueByName('is_cybersource_enabled', $store->id);
        $cybersource_pay_image = Utility::GetValueByName('cybersource_pay_image', $store->id);
        $cybersource_pay_unfo = Utility::GetValueByName('cybersource_pay_unfo', $store->id);
        $cybersource_pay_merchant_id = Utility::GetValueByName('cybersource_pay_merchant_id', $store->id);
        $cybersource_pay_secret_key = Utility::GetValueByName('cybersource_pay_secret_key', $store->id);
        $cybersource_pay_api_key = Utility::GetValueByName('cybersource_pay_api_key', $store->id);

        if (empty($cybersource_pay_image)) {
            $cybersource_pay_image = asset(Storage::url('uploads/payment/cybersource.png'));
        }

        $Setting_array[39]['status'] = !empty($is_cybersource_enabled) ? $is_cybersource_enabled : 'off';
        $Setting_array[39]['name_string'] = 'cybersource';
        $Setting_array[39]['name'] = 'CyberSource';
        $Setting_array[39]['detail'] = $cybersource_pay_unfo;
        $Setting_array[39]['image'] = $cybersource_pay_image;
        $Setting_array[39]['cybersource_pay_merchant_id'] = $cybersource_pay_merchant_id;
        $Setting_array[39]['cybersource_pay_secret_key'] = $cybersource_pay_secret_key;
        $Setting_array[39]['cybersource_pay_api_key'] = $cybersource_pay_api_key;

        //ozow
        $is_ozow_enabled = Utility::GetValueByName('is_ozow_enabled', $store->id);
        $ozow_pay_image = Utility::GetValueByName('ozow_pay_image', $store->id);
        $ozow_pay_unfo = Utility::GetValueByName('ozow_pay_unfo', $store->id);
        $ozow_mode = Utility::GetValueByName('ozow_mode', $store->id);
        $ozow_pay_Site_key = Utility::GetValueByName('ozow_pay_Site_key', $store->id);
        $ozow_pay_private_key = Utility::GetValueByName('ozow_pay_private_key', $store->id);
        $ozow_pay_api_key = Utility::GetValueByName('ozow_pay_api_key', $store->id);

        if (empty($ozow_pay_image)) {
            $ozow_pay_image = asset(Storage::url('uploads/payment/ozow.png'));
        }

        $Setting_array[40]['status'] = !empty($is_ozow_enabled) ? $is_ozow_enabled : 'off';
        $Setting_array[40]['name_string'] = 'ozow';
        $Setting_array[40]['name'] = 'Ozow';
        $Setting_array[40]['detail'] = $ozow_pay_unfo;
        $Setting_array[40]['image'] = $ozow_pay_image;
        $Setting_array[40]['ozow_mode'] = $ozow_mode;
        $Setting_array[40]['ozow_pay_Site_key'] = $ozow_pay_Site_key;
        $Setting_array[40]['ozow_pay_private_key'] = $ozow_pay_private_key;
        $Setting_array[40]['ozow_pay_api_key'] = $ozow_pay_api_key;

        //Easebuzz
        $is_easebuzz_enabled = Utility::GetValueByName( 'is_easebuzz_enabled' , $store->id);
        $easebuzz_image = Utility::GetValueByName( 'easebuzz_image' , $store->id);
        $easebuzz_unfo = Utility::GetValueByName( 'easebuzz_unfo' , $store->id);
        $easebuzz_merchant_key = Utility::GetValueByName( 'easebuzz_merchant_key' , $store->id);
        $easebuzz_salt_key = Utility::GetValueByName( 'easebuzz_salt_key' , $store->id);
        $easebuzz_enviroment_name = Utility::GetValueByName( 'easebuzz_enviroment_name' , $store->id);

        if ( empty( $easebuzz_image ) ) {
            $easebuzz_image = asset( Storage::url( 'uploads/payment/easebuzz.png' ) );
        }

        $Setting_array[ 41 ][ 'status' ] = !empty( $is_easebuzz_enabled ) ? $is_easebuzz_enabled : 'off';
        $Setting_array[ 41 ][ 'name_string' ] = 'easebuzz';
        $Setting_array[ 41 ][ 'name' ] = 'easebuzz';
        $Setting_array[ 41 ][ 'detail' ] = $easebuzz_unfo;
        $Setting_array[ 41 ][ 'image' ] = $easebuzz_image;
        $Setting_array[ 41 ][ 'easebuzz_merchant_key' ] = $easebuzz_merchant_key;
        $Setting_array[ 41 ][ 'easebuzz_salt_key' ] = $easebuzz_salt_key;
        $Setting_array[ 41 ][ 'easebuzz_enviroment_name' ] = $easebuzz_enviroment_name;

        //NMI
        $is_nmi_enabled = Utility::GetValueByName( 'is_nmi_enabled' , $store->id);
        $nmi_image = Utility::GetValueByName( 'nmi_image' , $store->id);
        $nmi_unfo = Utility::GetValueByName( 'nmi_unfo' , $store->id);
        $nmi_api_private_key = Utility::GetValueByName( 'nmi_api_private_key' , $store->id);

        if ( empty( $nmi_image ) ) {
            $nmi_image = asset( Storage::url( 'uploads/payment/nmi.png' ) );
        }

        $Setting_array[ 42 ][ 'status' ] = !empty( $is_nmi_enabled ) ? $is_nmi_enabled : 'off';
        $Setting_array[ 42 ][ 'name_string' ] = 'NMI';
        $Setting_array[ 42 ][ 'name' ] = 'NMI';
        $Setting_array[ 42 ][ 'detail' ] = $nmi_unfo;
        $Setting_array[ 42 ][ 'image' ] = $nmi_image;
        $Setting_array[ 42 ][ 'nmi_api_private_key' ] = $nmi_api_private_key;

        //PayU
        $is_payu_enabled = Utility::GetValueByName( 'is_payu_enabled', $store->id );
        $payu_mode = Utility::GetValueByName( 'payu_mode' , $store->id);
        $payu_merchant_key = Utility::GetValueByName( 'payu_merchant_key' , $store->id);
        $payu_salt_key = Utility::GetValueByName( 'payu_salt_key' , $store->id);
        $payu_image = Utility::GetValueByName( 'payu_image', $store->id );
        $payu_unfo = Utility::GetValueByName( 'payu_unfo' , $store->id);

        if ( empty( $payu_image ) ) {
            $payu_image = asset( Storage::url( 'uploads/payment/payu.png' ) );
        }

        $Setting_array[ 43 ][ 'status' ] = !empty( $is_payu_enabled ) ? $is_payu_enabled : 'off';
        $Setting_array[ 43 ][ 'name_string' ] = 'payu';
        $Setting_array[ 43 ][ 'name' ] = 'payu';
        $Setting_array[ 43 ][ 'detail' ] = $payu_unfo;
        $Setting_array[ 43 ][ 'image' ] = $payu_image;
        $Setting_array[ 43 ][ 'payu_mode' ] = $payu_mode;
        $Setting_array[ 43 ][ 'payu_merchant_key' ] = $payu_merchant_key;
        $Setting_array[ 43 ][ 'payu_salt_key' ] = $payu_salt_key;

        // Sofort
        $is_sofort_enabled = Utility::GetValueByName('is_sofort_enabled', $store->id);
        $sofort_publishable_key = Utility::GetValueByName('sofort_publishable_key', $store->id);
        $sofort_secret_key = Utility::GetValueByName('sofort_secret_key', $store->id);
        $sofort_image = Utility::GetValueByName('sofort_image', $store->id);
        if (empty($sofort_image)) {
            $sofort_image = asset(Storage::url('uploads/payment/sofort.png'));
        }
        $sofort_unfo = Utility::GetValueByName('sofort_unfo', $store->id);

        $Setting_array[44]['status'] = !empty($is_sofort_enabled) ? $is_sofort_enabled : 'off';
        $Setting_array[44]['name_string'] = 'sofort';
        $Setting_array[44]['name'] = 'sofort';
        $Setting_array[44]['detail'] = $sofort_unfo;
        $Setting_array[44]['image'] = $sofort_image;
        $Setting_array[44]['sofort_publishable_key'] = $sofort_publishable_key;
        $Setting_array[44]['sofort_secret_key_key'] = $sofort_secret_key;

        // ESewa
        $is_esewa_enabled = Utility::GetValueByName('is_esewa_enabled', $store->id);
        $esewa_merchant_key = Utility::GetValueByName('esewa_merchant_key', $store->id);
        $esewa_mode = Utility::GetValueByName('esewa_mode', $store->id);
        $esewa_image = Utility::GetValueByName('esewa_image', $store->id);
        if (empty($esewa_image)) {
            $esewa_image = asset(Storage::url('uploads/payment/esewa.png'));
        }
        $esewa_unfo = Utility::GetValueByName('esewa_unfo', $store->id);

        $Setting_array[45]['status'] = !empty($is_esewa_enabled) ? $is_esewa_enabled : 'off';
        $Setting_array[45]['name_string'] = 'esewa';
        $Setting_array[45]['name'] = 'esewa';
        $Setting_array[45]['detail'] = $esewa_unfo;
        $Setting_array[45]['image'] = $esewa_image;
        $Setting_array[45]['esewa_merchant_key'] = $esewa_merchant_key;
        $Setting_array[45]['esewa_mode_key'] = $esewa_mode;

        //MyFatoorah
        $is_myfatoorah_enabled = Utility::GetValueByName( 'is_myfatoorah_enabled', $store->id );
        $myfatoorah_pay_image = Utility::GetValueByName( 'myfatoorah_pay_image', $store->id );
        $myfatoorah_pay_unfo = Utility::GetValueByName( 'myfatoorah_pay_unfo', $store->id );
        $myfatoorah_mode = Utility::GetValueByName( 'myfatoorah_mode' );
        $myfatoorah_pay_country_iso = Utility::GetValueByName( 'myfatoorah_pay_country_iso', $store->id);
        $myfatoorah_pay_api_key = Utility::GetValueByName( 'myfatoorah_pay_api_key', $store->id );

        if ( empty( $myfatoorah_pay_image ) ) {
            $myfatoorah_pay_image = asset( Storage::url( 'uploads/payment/myfatoorah.png' ) );
        }

        $Setting_array[ 46 ][ 'status' ] = !empty( $is_myfatoorah_enabled ) ? $is_myfatoorah_enabled : 'off';
        $Setting_array[ 46 ][ 'name_string' ] = 'myfatoorah';
        $Setting_array[ 46 ][ 'name' ] = 'MyFatoorah';
        $Setting_array[ 46 ][ 'detail' ] = $myfatoorah_pay_unfo;
        $Setting_array[ 46 ][ 'image' ] = $myfatoorah_pay_image;
        $Setting_array[ 46 ][ 'myfatoorah_mode' ] = $myfatoorah_mode;
        $Setting_array[ 46 ][ 'myfatoorah_pay_country_iso' ] = $myfatoorah_pay_country_iso;
        $Setting_array[ 46 ][ 'myfatoorah_pay_api_key' ] = $myfatoorah_pay_api_key;

        //Paynow
        $is_paynow_enabled = Utility::GetValueByName( 'is_paynow_enabled', $store->id );
        $paynow_pay_image = Utility::GetValueByName( 'paynow_pay_image', $store->id );
        $paynow_pay_unfo = Utility::GetValueByName( 'paynow_pay_unfo', $store->id );
        $paynow_mode = Utility::GetValueByName( 'paynow_mode', $store->id );
        $paynow_pay_integration_id = Utility::GetValueByName( 'paynow_pay_integration_id', $store->id );
        $paynow_pay_integration_key = Utility::GetValueByName( 'paynow_pay_integration_key', $store->id );
        $paynow_pay_merchant_email = Utility::GetValueByName( 'paynow_pay_merchant_email', $store->id );

        if ( empty( $paynow_pay_image ) ) {
            $paynow_pay_image = asset( Storage::url( 'uploads/payment/paynow.png' ) );
        }

        $Setting_array[ 47 ][ 'status' ] = !empty( $is_paynow_enabled ) ? $is_paynow_enabled : 'off';
        $Setting_array[ 47 ][ 'name_string' ] = 'Paynow';
        $Setting_array[ 47 ][ 'name' ] = 'Paynow';
        $Setting_array[ 47 ][ 'detail' ] = $paynow_pay_unfo;
        $Setting_array[ 47 ][ 'image' ] = $paynow_pay_image;
        $Setting_array[ 47 ][ 'paynow_mode' ] = $paynow_mode;
        $Setting_array[ 47 ][ 'paynow_pay_integration_id' ] = $paynow_pay_integration_id;
        $Setting_array[ 47 ][ 'paynow_pay_integration_key' ] = $paynow_pay_integration_key;
        $Setting_array[ 47 ][ 'paynow_pay_merchant_email' ] = $paynow_pay_merchant_email;

        //DPO Pay
        $is_dpopay_enabled = Utility::GetValueByName( 'is_dpopay_enabled', $store->id );
        $dpo_pay_image = Utility::GetValueByName( 'dpo_pay_image', $store->id );
        $dpo_pay_unfo = Utility::GetValueByName( 'dpo_pay_unfo', $store->id );
        $dpo_pay_Company_Token = Utility::GetValueByName( 'dpo_pay_Company_Token', $store->id );
        $dpo_pay_Service_Type = Utility::GetValueByName( 'dpo_pay_Service_Type', $store->id );

        if ( empty( $dpo_pay_image ) ) {
            $dpo_pay_image = asset( Storage::url( 'uploads/payment/dpo.png' ) );
        }

        $Setting_array[ 48 ][ 'name_string' ] = 'DPOPay';
        $Setting_array[ 48 ][ 'status' ] = !empty( $is_dpopay_enabled ) ? $is_dpopay_enabled : 'off';
        $Setting_array[ 48 ][ 'name' ] = 'DPO';
        $Setting_array[ 48 ][ 'detail' ] = $dpo_pay_unfo;
        $Setting_array[ 48 ][ 'image' ] = $dpo_pay_image;
        $Setting_array[ 48 ][ 'dpo_pay_Company_Token' ] = $dpo_pay_Company_Token;
        $Setting_array[ 48 ][ 'dpo_pay_Service_Type' ] = $dpo_pay_Service_Type;


        //Braintree
        $is_braintree_enabled = Utility::GetValueByName( 'is_braintree_enabled', $store->id );
        $braintree_pay_image = Utility::GetValueByName( 'braintree_pay_image', $store->id );
        $braintree_pay_unfo = Utility::GetValueByName( 'braintree_pay_unfo', $store->id );
        $braintree_mode = Utility::GetValueByName( 'braintree_mode', $store->id );
        $braintree_pay_merchant_id = Utility::GetValueByName( 'braintree_pay_merchant_id', $store->id );
        $braintree_pay_public_key = Utility::GetValueByName( 'braintree_pay_public_key' , $store->id);
        $braintree_pay_private_key = Utility::GetValueByName( 'braintree_pay_private_key' , $store->id);

        if ( empty( $braintree_pay_image ) ) {
            $braintree_pay_image = asset( Storage::url( 'uploads/payment/braintree.png' ) );
        }

        $Setting_array[ 49 ][ 'status' ] = !empty( $is_braintree_enabled ) ? $is_braintree_enabled : 'off';
        $Setting_array[ 49 ][ 'name_string' ] = 'braintree';
        $Setting_array[ 49 ][ 'name' ] = 'Braintree';
        $Setting_array[ 49 ][ 'detail' ] = $braintree_pay_unfo;
        $Setting_array[ 49 ][ 'image' ] = $braintree_pay_image;
        $Setting_array[ 49 ][ 'braintree_mode' ] = $braintree_mode;
        $Setting_array[ 49 ][ 'braintree_pay_merchant_id' ] = $braintree_pay_merchant_id;
        $Setting_array[ 49 ][ 'braintree_pay_public_key' ] = $braintree_pay_public_key;
        $Setting_array[ 49 ][ 'braintree_pay_private_key' ] = $braintree_pay_private_key;

        //PowerTranz
        $is_powertranz_enabled = Utility::GetValueByName( 'is_powertranz_enabled', $store->id );
        $powertranz_pay_image = Utility::GetValueByName( 'powertranz_pay_image', $store->id );
        $powertranz_pay_unfo = Utility::GetValueByName( 'powertranz_pay_unfo', $store->id );
        $powertranz_mode = Utility::GetValueByName( 'powertranz_mode', $store->id );
        $powertranz_pay_production_url = Utility::GetValueByName( 'powertranz_pay_production_url', $store->id );
        $powertranz_pay_merchant_id = Utility::GetValueByName( 'powertranz_pay_merchant_id', $store->id );
        $powertranz_pay_processing_password = Utility::GetValueByName( 'powertranz_pay_processing_password', $store->id );

        if ( empty( $powertranz_pay_image ) ) {
            $powertranz_pay_image = asset( Storage::url( 'uploads/payment/powertranz.png' ) );
        }

        $Setting_array[ 50 ][ 'name_string' ] = 'powertranz';
        $Setting_array[ 50 ][ 'status' ] = !empty( $is_powertranz_enabled ) ? $is_powertranz_enabled : 'off';
        $Setting_array[ 50 ][ 'name' ] = 'PowerTranz';
        $Setting_array[ 50 ][ 'detail' ] = $powertranz_pay_unfo;
        $Setting_array[ 50 ][ 'image' ] = $powertranz_pay_image;
        $Setting_array[ 50 ][ 'powertranz_mode' ] = $powertranz_mode;
        $Setting_array[ 50 ][ 'powertranz_pay_production_url' ] = $powertranz_pay_production_url;
        $Setting_array[ 50 ][ 'powertranz_pay_merchant_id' ] = $powertranz_pay_merchant_id;
        $Setting_array[ 50 ][ 'powertranz_pay_processing_password' ] = $powertranz_pay_processing_password;

        // SSLCommerz
        $is_sslcommerz_enabled = Utility::GetValueByName( 'is_sslcommerz_enabled', $store->id );
        $sslcommerz_pay_image = Utility::GetValueByName( 'sslcommerz_pay_image', $store->id );
        $sslcommerz_pay_unfo = Utility::GetValueByName( 'sslcommerz_pay_unfo', $store->id );
        $sslcommerz_mode = Utility::GetValueByName( 'sslcommerz_mode', $store->id );
        $sslcommerz_pay_store_id = Utility::GetValueByName( 'sslcommerz_pay_store_id', $store->id );
        $sslcommerz_pay_secret_key = Utility::GetValueByName( 'sslcommerz_pay_secret_key' , $store->id);

        if ( empty( $sslcommerz_pay_image ) ) {
            $sslcommerz_pay_image = asset( Storage::url( 'uploads/payment/sslcommerz.png' ) );
        }

        $Setting_array[ 51 ][ 'name_string' ] = 'sslcommerz';
        $Setting_array[ 51 ][ 'status' ] = !empty( $is_sslcommerz_enabled ) ? $is_sslcommerz_enabled : 'off';
        $Setting_array[ 51 ][ 'name' ] = 'SSLCommerz';
        $Setting_array[ 51 ][ 'detail' ] = $sslcommerz_pay_unfo;
        $Setting_array[ 51 ][ 'image' ] = $sslcommerz_pay_image;
        $Setting_array[ 51 ][ 'sslcommerz_mode' ] = $sslcommerz_mode;
        $Setting_array[ 51 ][ 'sslcommerz_pay_store_id' ] = $sslcommerz_pay_store_id;
        $Setting_array[ 51 ][ 'sslcommerz_pay_secret_key' ] = $sslcommerz_pay_secret_key;
        
        if (module_is_active('PartialPayments')) {
            $user = User::find($store->created_by);
            $plan = Plan::find($user->plan_id);
            $enable_partial_payment = Utility::GetValueByName( 'enable_partial_payment', $store->id );
            $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
            if($plan && strpos($plan->modules, 'PartialPayments') !== false && \Auth::guard('customers')->user() && !isset($request->type) && $request->type != 'pending_amount' && isset($enable_partial_payment) && $enable_partial_payment == 'on'  && (isset($settings['enable_partial_payment']) && $settings['enable_partial_payment'] == 'on'))
            {
               $Setting_array = \Workdo\PartialPayments\app\Http\Controllers\PartialPaymentsController::PaymentList($Setting_array,$store);
            }
        }
        if ( !empty( $Setting_array ) ) {
            return $this->success( $Setting_array );
        } else {
            return $this->error(['message' => __('Payment not found.')]);
        }
    }

    public function place_order(Request $request, $slug = '')
    {
    $slug = !empty($slug) ? $slug : '';
    $store = getStore($slug);
    if (empty($store)) {
        return $this->error(['message' => __('Store not found.')], __("Store not found."));
    }

    // ✅ 1. أضف هذا السطر هنا لتعريف المتغير بشكل صحيح
    $storeId = $store->id;

    // ✅ 2. الآن سيعمل الكود بشكل صحيح لأن $storeId يحتوي على قيمة
$currentCurrencyCode = \App\Models\Utility::GetValueByName('CURRENCY', $storeId) ?: 'USD';
$currentCurrencySymbol = \App\Models\Utility::GetValueByName('currency_symbol', $storeId) ?: '$';
    $settings = Utility::Setting($storeId); // ✅ يفضل استخدام $storeId هنا أيضاً للاتساق
    
    
        $user = Cache::remember('admin_details', 3600, function () {
            return User::where('type','admin')->first();
        });
        if ($user->type == 'admin') {
            $plan = Plan::find($user->plan_id);
        }
        $rules = [
            'customer_id' => 'required',
            'billing_info' => 'required',
            'payment_type' => 'required',
        ];

        $validator = \Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $customer = Customer::find($request->customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $cartlist_final_price = 0;
        $final_price = 0;

        if (!empty($request->customer_id)) {
            $cart_list['customer_id']   = $request->customer_id;
            $request->request->add($cart_list);
            $cartlist_response = $this->cart_list($request, $slug);
            $cartlist = (array)$cartlist_response->getData()->data;
            if (empty($cartlist['product_list'])) {
                return $this->error(['message' => 'Cart is empty.']);
            }

            $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
            $final_sub_total_price = !empty($cartlist['total_sub_price']) ? $cartlist['total_sub_price'] : 0;
            $final_price = $cartlist['total_final_price'];
            $taxes = !empty($cartlist['tax_info']) ? $cartlist['tax_info'] : '';
            $billing = is_string($request->billing_info) ? (array) json_decode($request->billing_info) : $request->billing_info;
            $taxes = !empty($cartlist['tax_info']) ? $cartlist['tax_info'] : '';
            $products = $cartlist['product_list'];
        } else {
            return $this->error(['message' => 'User not found.']);
        }

        $coupon_price = 0;
        // coupon api call
        if (!empty($request->coupon_info)) {
            $coupon_data = $request->coupon_info;
            $apply_coupon = [
                'coupon_code' => $coupon_data['coupon_code'],
                'sub_total' => $cartlist_final_price
            ];
            $request->request->add($apply_coupon);
            $apply_coupon_response = $this->apply_coupon($request, $slug);

            $apply_coupon = (array)$apply_coupon_response->getData()->data;
            $order_array['coupon']['message'] = $apply_coupon['message'];
            $order_array['coupon']['status'] = false;
            if (!empty($apply_coupon['final_price'])) {
                $cartlist_final_price = $apply_coupon['final_price'];
                $coupon_price = $apply_coupon['amount'];
                $order_array['coupon']['status'] = true;
            }
        }

        $delivery_price = 0;
        if ($plan->shipping_method == 'on') {
            if (!empty($request->method_id)) {
                $del_charge = new CartController();
                $delivery_charge = $del_charge->get_shipping_method($request, $slug);
                $content = $delivery_charge->getContent();
                $data = json_decode($content, true);
                $delivery_price = $data['total_final_price'];
                $tax_price = $data['final_tax_price'];
            } 
            // else {
            //     return $this->error(['message' => __('Shipping Method not found')]);
            // }
        } else {
            $tax_price = 0;
            if (!empty($taxes)) {
                foreach ($taxes as $key => $tax) {
                    $tax_price += $tax->tax_price;
                }
            }
        }

        // Order stock decrease start
        $prodduct_id_array = [];
        if (!empty($products)) {
            foreach ($products as $key => $product) {
                $prodduct_id_array[] = $product->product_id;

                $product_id = $product->product_id;
                $variant_id = $product->variant_id;
                $qtyy = !empty($product->qty) ? $product->qty : 0;

                $Product = Product::where('id', $product_id)->first();
                $datas = Product::find($product_id);

                if ($settings['stock_management'] == 'on') {
                    if (!empty($product_id) && !empty($variant_id) && $product_id != 0 && $variant_id != 0) {
                        $ProductStock = ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first();
                        $variationOptions = explode(',', $ProductStock->variation_option);
                        $option = in_array('manage_stock', $variationOptions);
                        if (!empty($ProductStock)) {
                            if ($option == true) {
                                $remain_stock = $ProductStock->stock - $qtyy;
                                $ProductStock->stock = $remain_stock;
                                $ProductStock->save();

                                if ($ProductStock->stock <= $ProductStock->low_stock_threshold) {
                                    if (!empty(json_decode($settings['notification'])) && in_array('enable_low_stock', json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == 'on') {
                                            Utility::variant_low_stock_threshold($product, $ProductStock, $settings);
                                        }
                                    }
                                }
                                if ($ProductStock->stock <= $settings['out_of_stock_threshold']) {
                                    if (!empty(json_decode($settings['notification'])) && in_array('enable_out_of_stock', json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == 'on') {
                                            Utility::variant_out_of_stock($product, $ProductStock, $settings);
                                        }
                                    }
                                }
                            } else {
                                $remain_stock = $datas->product_stock - $qtyy;
                                $datas->product_stock = $remain_stock;
                                $datas->save();
                                if ($datas->product_stock <= $datas->low_stock_threshold) {
                                    if (!empty(json_decode($settings['notification'])) && in_array('enable_low_stock', json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == 'on') {
                                            Utility::variant_low_stock_threshold($product, $datas, $settings);
                                        }
                                    }
                                }
                                if ($datas->product_stock <= $settings['out_of_stock_threshold']) {
                                    if (!empty(json_decode($settings['notification'])) && in_array('enable_out_of_stock', json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == 'on') {
                                            Utility::variant_out_of_stock($product, $datas, $settings);
                                        }
                                    }
                                }
                                if ($datas->product_stock <= $settings['out_of_stock_threshold'] && $datas->stock_order_status == 'notify_customer') {
                                    //Stock Mail
                                    $order_email = $billing['email'];
                                    $owner = User::find($store->created_by);
                                    $ProductId    = '';

                                    try {
                                        $dArr = [
                                            'item_variable' => $Product->id,
                                            'product_name' => $Product->name,
                                            'customer_name' => $billing['firstname'],
                                        ];

                                        // Send Email
                                        $resp = Utility::sendEmailTemplate('Stock Status', $order_email, $dArr, $owner, $store, $ProductId);
                                    } catch (\Exception $e) {
                                        $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                                    }
                                    try {
                                        $mobile_no = $request['billing_info']['billing_user_telephone'];
                                        $customer_name = $request['billing_info']['firstname'];
                                        $msg =   __("Dear,$customer_name .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :$Product->name. ");
                                        $resp  = Utility::SendMsgs('Stock Status', $mobile_no, $msg);
                                    } catch (\Exception $e) {
                                        $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
                                    }
                                }
                            }
                        } else {
                            return $this->error(['message' => 'Product not found .']);
                        }
                    } elseif (!empty($product_id) && $product_id != 0) {

                        if (!empty($Product)) {
                            $remain_stock = $Product->product_stock - $qtyy;
                            $Product->product_stock = $remain_stock;
                            $Product->save();
                            if ($Product->product_stock <= $Product->low_stock_threshold) {
                                if (!empty(json_decode($settings['notification'])) && in_array('enable_low_stock', json_decode($settings['notification']))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == 'on') {
                                        Utility::low_stock_threshold($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= $settings['out_of_stock_threshold']) {
                                if (!empty(json_decode($settings['notification'])) && in_array('enable_out_of_stock', json_decode($settings['notification']))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == 'on') {
                                        Utility::out_of_stock($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= $settings['out_of_stock_threshold'] && $Product->stock_order_status == 'notify_customer') {
                                //Stock Mail
                                $order_email = $request['billing_info']['email'];
                                $owner = User::find($store->created_by);
                                // $owner_email = $owner->email;
                                $ProductId    = '';

                                try {
                                    $dArr = [
                                        'item_variable' => $Product->id,
                                        'product_name' => $Product->name,
                                        'customer_name' => $request['billing_info']['firstname'],
                                    ];

                                    // Send Email
                                    $resp = Utility::sendEmailTemplate('Stock Status', $order_email, $dArr, $owner, $store, $ProductId);
                                } catch (\Exception $e) {
                                    $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                                }

                                try {
                                    $mobile_no = $request['billing_info']['billing_user_telephone'];
                                    $customer_name = $request['billing_info']['firstname'];
                                    $msg =   __("Dear,$customer_name .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :$Product->name. ");

                                    $resp  = Utility::SendMsgs('Stock Status', $mobile_no, $msg);
                                } catch (\Exception $e) {
                                    $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
                                }
                            }
                        } else {
                            return $this->error(['message' => __('Product not found .')]);
                        }
                    } else {
                        return $this->error(['message' => __('Please fill proper product json field .')]);
                    }
                }
                // remove from cart
                Cart::where( 'customer_id', $request->customer_id )->where( 'product_id', $product_id )->where( 'variant_id', $variant_id )->where( 'store_id', $store->id )->delete();
            }
        }
        // Order stock decrease end

        if (!empty($prodduct_id_array)) {
            $prodduct_id_array = $prodduct_id_array = array_unique($prodduct_id_array);
            $prodduct_id_array = implode(',', $prodduct_id_array);
        } else {
            $prodduct_id_array = '';
        }

        $product_reward_point = 1;

        // add in  Order table  start
        $order = new Order();
        $order->product_order_id = $request->customer_id . date('YmdHis');
        $order->order_date = date('Y-m-d H:i:s');
        $order->customer_id = $request->customer_id;
        $order->product_id = $prodduct_id_array;
        $order->product_json = json_encode($products);
        $order->product_price = $final_sub_total_price;
        $order->coupon_price = $coupon_price;
        $order->delivery_price = $delivery_price;
        $order->tax_price = $tax_price;
        $order->currency_code = $currentCurrencyCode;
        $order->currency_symbol = $currentCurrencySymbol;


        if (!\Auth::guard('customers')->user()) {
            if ($plan->shipping_method == 'on') {
                $order->final_price = $data['shipping_total_price'];
            } else {
                $order->final_price = $final_price + $tax_price;
            }
        } else {
            if ($plan->shipping_method == 'on') {
                $order->final_price = $data['shipping_total_price'] + $tax_price;
            } else {
                $order->final_price = $final_price + $tax_price;
            }
        }
        event(new AddAdditionalFields($order, $request->all(), $store));
        $order->payment_comment = $request->payment_comment;
        $order->payment_type = $request->payment_type;
        $order->payment_status = 'Unpaid';
        $order->delivery_id =  $requests_data['method_id'] ?? 0;
        $order->delivery_comment = $request->delivery_comment;
        $order->delivered_status = 0;
        $order->reward_points = SetNumber($product_reward_point);
        $order->additional_note = $request->additional_note;
        $order->store_id = $store->id;
        $order->save();

        Utility::paymentWebhook( $order );
        // add in  Order table end

        // add in  Order Billing Detail table start
        $billing_city_id = 0;
        if (!empty($billing['billing_city'])) {
            $cityy = City::where('name', $billing['billing_city'])->first();
            if (!empty($cityy)) {
                $billing_city_id = $cityy->id;
            } else {
                $new_billing_city = new City();
                $new_billing_city->name = $billing['billing_city'];
                $new_billing_city->state_id = $billing['billing_state'];
                $new_billing_city->country_id = $billing['billing_country'];
                $new_billing_city->save();
                $billing_city_id = $new_billing_city->id;
            }
        }

        $delivery_city_id = 0;
        if (!empty($billing['delivery_city'])) {
            $d_cityy = City::where('name', $billing['delivery_city'])->first();
            if (!empty($d_cityy)) {
                $delivery_city_id = $d_cityy->id;
            } else {
                $new_delivery_city = new City();
                $new_delivery_city->name = $billing['delivery_city'];
                $new_delivery_city->state_id = $billing['delivery_state'];
                $new_delivery_city->country_id = $billing['delivery_country'];
                $new_delivery_city->save();
                $delivery_city_id = $new_delivery_city->id;
            }
        }
        if (is_string($request->billing_info)) {
            $other_info = json_decode($request->billing_info);
        } else {
            $other_info = is_array($request->billing_info) ? (object) $request->billing_info : $request->billing_info;
        }

        $OrderBillingDetail = new OrderBillingDetail();
        $OrderBillingDetail->order_id = $order->id;
        $OrderBillingDetail->product_order_id = $order->product_order_id;
        $OrderBillingDetail->first_name = $other_info->firstname;
        $OrderBillingDetail->last_name = $other_info->lastname;
        $OrderBillingDetail->email = $other_info->email;
        $OrderBillingDetail->telephone = $other_info->billing_user_telephone;
        $OrderBillingDetail->address = $other_info->billing_address;
        $OrderBillingDetail->postcode = $other_info->billing_postecode;
        $OrderBillingDetail->country = $other_info->billing_country;
        $OrderBillingDetail->state = $other_info->billing_state;
        $OrderBillingDetail->city = $other_info->billing_city;
        $OrderBillingDetail->delivery_address = $other_info->delivery_address;
        $OrderBillingDetail->delivery_city = $other_info->delivery_city;
        $OrderBillingDetail->delivery_postcode = $other_info->delivery_postcode;
        $OrderBillingDetail->delivery_country = $other_info->delivery_country;
        $OrderBillingDetail->delivery_state = $other_info->delivery_state;
        $OrderBillingDetail->save();
        // add in Order Billing Detail table end

        // add in Order Coupon Detail table start
        if (!empty($request->coupon_info)) {
            $coupon_data = $request->coupon_info;
            $Coupon = Coupon::find($coupon_data['coupon_id']);

            // Order Coupon history
            $OrderCouponDetail = new OrderCouponDetail();
            $OrderCouponDetail->order_id = $order->id;
            $OrderCouponDetail->product_order_id = $order->product_order_id;
            $OrderCouponDetail->coupon_id = $coupon_data['coupon_id'];
            $OrderCouponDetail->coupon_name = $coupon_data['coupon_name'];
            $OrderCouponDetail->coupon_code = $coupon_data['coupon_code'];
            $OrderCouponDetail->coupon_discount_type = $coupon_data['coupon_discount_type'];
            $OrderCouponDetail->coupon_discount_number = $coupon_data['coupon_discount_number'];
            $OrderCouponDetail->coupon_discount_amount = $coupon_data['coupon_discount_amount'];
            $OrderCouponDetail->coupon_final_amount = $coupon_data['coupon_final_amount'];
            $OrderCouponDetail->save();

            // Coupon history
            $UserCoupon = new UserCoupon();
            $UserCoupon->user_id = $request->user_id;
            $UserCoupon->coupon_id = $Coupon->id;
            $UserCoupon->amount = $coupon_data['coupon_discount_amount'];
            $UserCoupon->order_id = $order->id;
            $UserCoupon->date_used = now();
            $UserCoupon->save();

            $discount_string = '-' . $coupon_data['coupon_discount_amount'];
            $CURRENCY = Utility::GetValueByName('CURRENCY');
            $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME');
            if ($coupon_data['coupon_discount_type'] == 'flat') {
                $discount_string .= $CURRENCY;
            } else {
                $discount_string .= '%';
            }

            $discount_string .= ' ' . __('for all products');
            $order_array['coupon']['code'] = $coupon_data['coupon_code'];
            $order_array['coupon']['discount_string'] = $discount_string;
            $order_array['coupon']['price'] = SetNumber($coupon_data['coupon_final_amount']);
        }
        // add in Order Coupon Detail table end

        // add in Order Tax Detail table start
        if (!empty($taxes)) {
            foreach ($taxes as $key => $tax) {
                $OrderTaxDetail = new OrderTaxDetail();
                $OrderTaxDetail->order_id = $order->id;
                $OrderTaxDetail->product_order_id = $order->product_order_id;
                $OrderTaxDetail->tax_id = $tax->id;
                $OrderTaxDetail->tax_name = $tax->tax_name;
                $OrderTaxDetail->tax_discount_type = $tax->tax_type;
                $OrderTaxDetail->tax_discount_amount = !empty($tax->tax_amount) ? $tax->tax_amount : 0;
                $OrderTaxDetail->tax_final_amount = $tax->tax_price;
                $OrderTaxDetail->save();

                $order_array['tax'][$key]['tax_string'] = $tax->tax_string;
                $order_array['tax'][$key]['tax_price'] = $tax->tax_price;
            }
        }

        //activity log
        ActivityLog::order_entry(['customer_id' => $order->customer_id, 'order_id' => $order->product_order_id, 'order_date' => $order->order_date, 'products' => $order->product_id, 'final_price' => $order->final_price, 'payment_type' => $order->payment_type, 'store_id' => $order->store_id]);

        //Order Mail
        $order_email = $OrderBillingDetail->email;
        $owner = User::find($store->created_by);
        $owner_email = $owner->email;
        $order_id    = Crypt::encrypt($order->id);

        try {
            $dArr = [
                'order_id' => $order->product_order_id,
            ];

            // Send Email
            $resp = Utility::sendEmailTemplate('Order Created', $order_email, $dArr, $owner, $store, $order_id);
            $resp1 = Utility::sendEmailTemplate('Order Created For Owner', $owner_email, $dArr, $owner, $store, $order_id);
        } catch (\Exception $e) {
            $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
        }

        foreach ($products as $product) {
            $product_data = Product::find($product->product_id);

            if ($product_data) {
                if ($product_data->variant_product == 0) {
                    if ($product_data->track_stock == 1) {
                        OrderNote::order_note_data([
                            'user_id' => !empty($request->user_id) ? $request->user_id : '0',
                            'order_id' => $order->id,
                            'product_name' => !empty($product_data->name) ? $product_data->name : '',
                            'variant_product' => $product_data->variant_product,
                            'product_stock' => !empty($product_data->product_stock) ? $product_data->product_stock : '',
                            'status' => 'Stock Manage',
                            'store_id' => $order->store_id,
                        ]);
                    }
                } else {

                    $variant_data = ProductVariant::find($product->variant_id);
                    $variationOptions = explode(',', $variant_data->variation_option);
                    $option = in_array('manage_stock', $variationOptions);
                    if ($option == true) {
                        OrderNote::order_note_data([
                            'user_id' => !empty($request->user_id) ? $request->user_id : '0',
                            'order_id' => !empty($order->id) ? $order->id : '',
                            'product_name' => !empty($product_data->name) ? $product_data->name : '',
                            'variant_product' => $product_data->variant_product,
                            'product_variant_name' => !empty($variant_data->variant) ? $variant_data->variant : '',
                            'product_stock' => !empty($variant_data->stock) ? $variant_data->stock : '',
                            'status' => 'Stock Manage',
                            'store_id' => $order->store_id,
                        ]);
                    }
                }
            }
        }

        OrderNote::order_note_data([
            'user_id' => !empty($request->user_id) ? $request->user_id : '0',
            'order_id' => $order->id,
            'product_order_id' => $order->product_order_id,
            'delivery_status' => 'Pending',
            'status' => 'Order Created',
            'store_id' => $order->store_id
        ]);

        try {
            $msg = __("Hello, Welcome to $store->name .Hi,your order id is $order->product_order_id, Thank you for Shopping We received your purchase request, we'll be in touch shortly!. ");
            $mess = Utility::SendMsgs('Order Created', $OrderBillingDetail->telephone, $msg);
        } catch (\Exception $e) {
            $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
        }
        // add in Order Tax Detail table end
        if (!empty($order) && !empty($OrderBillingDetail)) {
            $order_array['order_id'] = $order->id;

            $order_array['order_id'] = $order->id;
            $cart_array = [];
            $cart_json = json_encode($cart_array);

            return $this->success(['order_id' => $order->id, 'slug' => $slug]);
        } else {
            return $this->error(['message' => __('Somthing went wrong.')]);
        }
    }

    public function add_address(Request $request, $slug = '')
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        if (auth('customers')->user()) {

            $rules = [

                'title' => 'required',

                'address' => 'required',

                'country' => 'required|exists:countries,id',

                'state' => 'required|exists:states,id',

                'city' => 'required',

                'postcode' => 'required',

                'default_address' => 'required',

            ];



            $customer_id = auth('customers')->user()->id ?? null;
        } else {

            $rules = [

                'customer_id' => 'required',

                'title' => 'required',

                'address' => 'required',

                'country' => 'required|exists:countries,id',

                'state' => 'required|exists:states,id',

                'city' => 'required',

                'postcode' => 'required',

                'default_address' => 'required',

            ];

            $customer_id = $request->customer_id ?? null;
        }

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ]);
        }
        $customer = Customer::find($customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $user = new DeliveryAddress();
        $default_address = !empty($request->default_address) ? 1 : 0;
        $user->title = $request->title;
        $user->country_id = !empty($request->country) ? $request->country : null;
        $user->state_id = !empty($request->state) ? $request->state : null;
        $user->city_id = !empty($request->city) ? $request->city : null;
        $user->customer_id = $customer_id ?? null;
        $user->title = !empty($request->title) ? $request->title : null;
        $user->address = !empty($request->address) ? $request->address : null;
        $user->postcode = !empty($request->postcode) ? $request->postcode : null;
        $user->default_address = $default_address;
        $user->store_id = $store->id;
        $user->save();

        if ($default_address == 1) {
            $u_a_a['default_address'] = 0;
            DeliveryAddress::where('customer_id', $request->customer_id)->where('id', '!=', $user->id)->update($u_a_a);
        }
        return $this->success(['message' => __('Address added success.')]);
    }

    public function apply_coupon(Request $request, $slug = '')
    {
        $user = auth('customers')->user();
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        $slug = !empty($slug) ? $slug : $store->slug;
        
        $shipping_Methods = \Session::get('shipping_method');
        if ($shipping_Methods != null) {
            $shipp = new CartController();
            $ship = $shipp->get_shipping_data($request, $slug);
            $shipping_Methods = $ship->original['shipping_method'] ?? [];
        }
        \Session::forget('shipping_method');
        $CURRENCY = Utility::GetValueByName('CURRENCY', $store->id);
        $couponQuery = Coupon::query();
        $code = trim($request->coupon_code);
        $coupon = (clone $couponQuery)->whereRaw('BINARY `coupon_code` = ?', [$code])->where('store_id', $store->id)->first();
        if (!empty($coupon)) {
            $coupon_count = $coupon->UsesCouponCount();
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
                    return $this->error(['message' => __("Sorry, you've exceeded the usage limit for this coupon. Please choose another coupon or proceed without applying one.")]);
                }
            }
            if (empty($coupon_expiry_date)) {
                return $this->error(['message' => __('This coupon has expired.')]);
            }

            if ($coupon->free_shipping_coupon == 0) {

                if ($request->final_sub_total != null) {
                    $sub_total_min = $request->final_sub_total;
                } else {
                    $sub_total_min = $request->sub_total;
                }

                if ($sub_total_min <= $coupon->maximum_spend  || $coupon->maximum_spend == null) {
                    if ($sub_total_min >= $coupon->minimum_spend ||  $coupon->minimum_spend == null) {
                        if ($request->final_sub_total != null) {

                            $price = $request->final_sub_total;
                        } else {
                            $price = $request->sub_total;
                        }
                        $amount = $coupon->discount_amount;
                        if ($coupon->sale_items != 0) {
                            $currentDate = Carbon::now()->toDateString();
                            $falsh_sale = FlashSale::where('store_id', $store->id)->where('is_active', 1)->where('start_date', '<=', $currentDate)->where('end_date', '>=', $currentDate)->get();
                            $saleEnableArray = [];
                            foreach ($falsh_sale as $sale) {
                                $saleEnableArray[] = json_decode($sale->sale_product, true);
                            }
                            $combinedArray = array_merge(...$saleEnableArray);
                            $saleproducts = array_unique($combinedArray);
                        } else {
                            $saleproducts = [];
                        }
                        if (Utility::CustomerAuthCheck($store->slug) != true) {
                            $response = Cart::cart_list_cookie($request->all(), $store->id);
                            $response = json_decode(json_encode($response));
                        } else {
                            $request->merge(['customer_id' => auth('customers')->user()->id, 'store_id' => $store->id, 'slug' => $slug]);
                            $api = new ApiController();
                            $data = $api->cart_list($request);
                            $response = $data->getData();
                        }
                        $produt_id = [];
                        foreach ($response->data->product_list as $item) {
                            $produt_id[] = $item->product_id;
                        }
                        $produt_ids = array_map('intval', $produt_id);

                        if (empty(array_diff($saleproducts, $produt_ids)) && empty(array_diff($produt_ids, $saleproducts)) == true) {
                            return $this->error(['message' => __('This coupon has expired.')]);
                        }

                        if ($coupon->coupon_type == 'flat') {
                            $price -= $amount;
                        }

                        if ($coupon->coupon_type == 'percentage') {
                            if ($request->final_sub_total != null) {
                                $sub_totals = $request->final_sub_total;
                            } else {
                                $sub_totals = $request->sub_total;
                            }
                            $amount = $amount * $sub_totals / 100;
                            $price -= $amount;
                        }
                        if ($coupon->coupon_type == 'fixed product discount') {
                            $coupon_applied = explode(',', ($coupon->applied_product));
                            $exclude_product = explode(',', $coupon->exclude_product);
                            $applied_categories = explode(',', $coupon->applied_categories);
                            $exclude_categories = explode(',', $coupon->exclude_categories);
                            $total_price = [];
                            $quty = [];
                            $product = [];

                            foreach ($response->data->product_list as $item) {
                                $product[] = $item->final_price;
                            }
                            $final_sub_total_sum = array_sum($product);

                            foreach ($response->data->product_list as $item) {

                                $quty[] = $item->qty;

                                $cat = Product::where('id', $item->product_id)->where('store_id', $store->id)->pluck('category_id')->first();

                                if ($coupon->sale_items != 0) {
                                    $currentDate = Carbon::now()->toDateString();
                                    $falsh_sale = FlashSale::where('store_id', $store->id)->where('is_active', 1)->where('start_date', '<=', $currentDate)->where('end_date', '>=', $currentDate)->get();
                                    $saleEnableArray = [];
                                    foreach ($falsh_sale as $sale) {
                                        $saleEnableArray[] = json_decode($sale->sale_product, true);
                                    }
                                    $combinedArray = array_merge(...$saleEnableArray);
                                    $saleproduct = array_unique($combinedArray);
                                } else {
                                    $saleproduct = [];
                                }
                                if ($applied_categories[0] !=  '' ||  $exclude_categories[0] !=  '') {
                                    $common_cat = array_intersect($applied_categories, $exclude_categories);
                                    if (in_array($cat, $common_cat)) {
                                        $apply_product  = $item->final_price;
                                        $apply_product -= 0;
                                        $total_price[] = $apply_product;
                                    } else {
                                        if ($applied_categories[0] ==  ''  &&  $exclude_categories[0] !=  '') {
                                            if ($exclude_categories[0] !=  '' && $applied_categories[0] ==  '' && $coupon_applied[0] ==  '') {
                                                if (in_array($cat, $exclude_categories)) {
                                                    $apply_product = $item->final_price;
                                                    $apply_product -= 0;
                                                    $total_price[] = $apply_product;
                                                } else {
                                                    if (in_array($item->product_id, $exclude_product)) {
                                                        $apply_product = $item->final_price;
                                                        $apply_product -= 0;
                                                        $total_price[] = $apply_product;
                                                    } else {
                                                        if (in_array($item->product_id, $saleproduct)) {
                                                            $apply_product  = $item->final_price;
                                                            $apply_product -= 0;
                                                            $total_price[] = $apply_product;
                                                        } else {
                                                            $apply_product = $item->final_price;
                                                            $apply_product -= $amount * $item->qty;
                                                            $total_price[] = $apply_product;
                                                        }
                                                    }
                                                }
                                            } else {
                                                if (in_array($cat, $exclude_categories)) {
                                                    if (in_array($item->product_id, $coupon_applied) == true) {
                                                        $apply_product = $item->final_price;
                                                        $apply_product -= 0;
                                                        $total_price[] = $apply_product;
                                                    } else {
                                                        if (in_array($item->product_id, $coupon_applied) == true) {
                                                            if (in_array($item->product_id, $saleproduct)) {
                                                                $apply_product  = $item->final_price;
                                                                $apply_product -= 0;
                                                                $total_price[] = $apply_product;
                                                            } else {
                                                                $apply_product = $item->final_price;
                                                                $apply_product -= $amount * $item->qty;
                                                                $total_price[] = $apply_product;
                                                            }
                                                        } else {
                                                            $apply_product = $item->final_price;
                                                            $apply_product -= 0;
                                                            $total_price[] = $apply_product;
                                                        }
                                                    }
                                                } else {
                                                    if (in_array($item->product_id, $coupon_applied) == true) {
                                                        if (in_array($item->product_id, $saleproduct)) {
                                                            $apply_product  = $item->final_price;
                                                            $apply_product -= 0;
                                                            $total_price[] = $apply_product;
                                                        } else {
                                                            $apply_product = $item->final_price;
                                                            $apply_product -= $amount * $item->qty;
                                                            $total_price[] = $apply_product;
                                                        }
                                                    } else {
                                                        $apply_product = $item->final_price;
                                                        $apply_product -= 0;
                                                        $total_price[] = $apply_product;
                                                    }
                                                }
                                            }
                                        } else {

                                            if (in_array($cat, $applied_categories)) {
                                                // if exxlude product and applied_categories
                                                if (in_array($item->product_id, $exclude_product) == true) {
                                                    $apply_product  = $item->final_price;
                                                    $apply_product -= 0;
                                                    $total_price[] = $apply_product;
                                                } else {
                                                    if (in_array($cat, $applied_categories) && in_array($item->product_id, $coupon_applied)) {
                                                        if (in_array($item->product_id, $saleproduct)) {
                                                            $apply_product  = $item->final_price;
                                                            $apply_product -= 0;
                                                            $total_price[] = $apply_product;
                                                        } else {
                                                            $apply_product = $item->final_price;
                                                            $apply_product -= $amount * $item->qty;
                                                            $total_price[] = $apply_product;
                                                        }
                                                    } else {
                                                        $apply_product  = $item->final_price;
                                                        $apply_product -= 0;
                                                        $total_price[] = $apply_product;
                                                    }
                                                }
                                            } else {
                                                // if not this product catgory in  applied_categories but product in  coupon_applied
                                                $apply_product  = $item->final_price;
                                                $apply_product -= 0;
                                                $total_price[] = $apply_product;
                                            }
                                        }
                                    }

                                    $price = array_sum($total_price);
                                    $discount_amounts = $final_sub_total_sum - $price;
                                } else {
                                    if ($coupon_applied[0] ==  '' &&  $exclude_product[0] ==  '') {
                                        if (in_array($item->product_id, $saleproduct)) {
                                            $apply_product  = $item->final_price;
                                            $apply_product -= 0;
                                            $total_price[] = $apply_product;
                                        } else {
                                            if (in_array($item->product_id, $saleproduct)) {
                                                $apply_product  = $item->final_price;
                                                $apply_product -= 0;
                                                $total_price[] = $apply_product;
                                            } else {
                                                $apply_product = $item->final_price;
                                                $apply_product -= $amount * $item->qty;
                                                $total_price[] = $apply_product;
                                            }
                                        }

                                        $price = array_sum($total_price);
                                        $discount_amounts = $final_sub_total_sum - $price;
                                    } else {

                                        if ($coupon_applied[0] ==  '') {
                                            if (in_array($item->product_id, $exclude_product)) {
                                                $apply_product  = $item->final_price;
                                                $apply_product -= 0;
                                                $total_price[] = $apply_product;
                                            } else {
                                                if (in_array($item->product_id, $saleproduct)) {
                                                    $apply_product  = $item->final_price;
                                                    $apply_product -= 0;
                                                    $total_price[] = $apply_product;
                                                } else {
                                                    $apply_product = $item->final_price;
                                                    $apply_product -= $amount * $item->qty;
                                                    $total_price[] = $apply_product;
                                                }
                                            }
                                        } else {

                                            $common_values = array_intersect($coupon_applied, $exclude_product);

                                            if (in_array($item->product_id, $coupon_applied)) {

                                                if (in_array($item->product_id, $common_values)) {
                                                    $apply_product  = $item->final_price;
                                                    $apply_product  -= 0;
                                                    $total_price[] = $apply_product;
                                                } else {

                                                    if (in_array($item->product_id, $saleproduct)) {
                                                        $apply_product  = $item->final_price;
                                                        $apply_product -= 0;
                                                        $total_price[] = $apply_product;
                                                    } else {
                                                        $apply_product = $item->final_price;
                                                        $apply_product -= $amount * $item->qty;
                                                        $total_price[] = $apply_product;
                                                    }
                                                }
                                            } else {

                                                $apply_product  = $item->final_price;
                                                $apply_product -= 0;
                                                $total_price[] = $apply_product;
                                            }
                                        }

                                        $price = array_sum($total_price);
                                        $discount_amounts = $final_sub_total_sum - $price;
                                    }
                                }
                            }

                            if ($coupon->coupon_limit_x_item != null) {
                                $intArray = array_map('intval', $quty);
                                $sum = array_sum($intArray);
                                $total_amount  = $discount_amounts / $sum;
                                if ($sum  >= $coupon->coupon_limit_x_item) {

                                    $discount_amounts =  $total_amount * $coupon->coupon_limit_x_item;
                                } else {

                                    $discount_amounts =  $total_amount *  $sum;
                                }
                            }
                            if ($coupon->discount_amount != 0 && $discount_amounts == 0) {
                                return $this->error(['message' => __(' Sorry, this coupon is not applicable to selected products.')]);
                            }
                        } else {
                            return $this->error(['message' => ' The minimum spend for this coupon is ' . (currency_format_with_sym($coupon->minimum_spend, $store->id) ?? SetNumberFormat($coupon->minimum_spend)) . '.']);
                        }
                    } else {
                        return $this->error(['message' => ' The maximum spend for this coupon is ' . (currency_format_with_sym($coupon->maximum_spend, $store->id) ?? SetNumberFormat($coupon->maximum_spend)) . '.']);
                    }

                    $coupon_array['message'] = __('Coupon is valid.');
                    $coupon_array['id'] = $coupon->id;
                    $coupon_array['name'] = $coupon->coupon_name;
                    $coupon_array['code'] = $coupon->coupon_code;
                    $coupon_array['coupon_discount_type'] = $coupon->coupon_type;
                    if ($coupon->coupon_type == 'fixed product discount') {

                        $coupon_array['coupon_discount_amount'] = $discount_amounts;
                    } else {
                        $coupon_array['coupon_discount_amount'] = $coupon->discount_amount;
                    }

                    $coupon_array['coupon_end'] = '----------------------';
                    $coupon_array['original_price'] = SetNumber($request->sub_total);
                    $coupon_array['final_price'] = SetNumber($price);
                    $coupon_array['discount_price'] = SetNumber($price);
                    if ($coupon->coupon_type == 'fixed product discount') {
                        $coupon_array['amount'] = SetNumber($discount_amounts);
                        $coupon_array['discount_amount_currency'] = currency_format_with_sym($discount_amounts, $store->id) ?? SetNumberFormat($discount_amounts);
                    } else {
                        $coupon_array['amount'] = SetNumber($amount);
                        $coupon_array['discount_amount_currency'] = currency_format_with_sym($amount, $store->id) ?? SetNumberFormat($amount);
                    }
                    $coupon_array['shipping_total_price'] = currency_format_with_sym($price, $store->id) ?? SetNumberFormat($price);
                }
            } else {

                $amount = $coupon->discount_amount;
                if ($shipping_Methods != null) {

                    foreach ($shipping_Methods as $shippingMethod) {
                        if ($shippingMethod->method_name) {
                            if ($shippingMethod->cost < $request->final_sub_total) {
                                $price = $request->final_sub_total;
                                $amount = $coupon->discount_amount;
                                if ($request->final_sub_total != null) {
                                    $sub_total_min = $request->final_sub_total;
                                } else {
                                    $sub_total_min = $request->sub_total;
                                }
                                if ($sub_total_min <= $coupon->maximum_spend  || $coupon->maximum_spend == null) {
                                    if ($sub_total_min >= $coupon->minimum_spend || $coupon->minimum_spend == null) {
                                        if ($coupon->sale_items != 0) {
                                            $currentDate = Carbon::now()->toDateString();
                                            $falsh_sale = FlashSale::where('store_id', $store->id)->where('is_active', 1)->where('start_date', '<=', $currentDate)->where('end_date', '>=', $currentDate)->get();
                                            $saleEnableArray = [];
                                            foreach ($falsh_sale as $sale) {
                                                $saleEnableArray[] = json_decode($sale->sale_product, true);
                                            }
                                            $combinedArray = array_merge(...$saleEnableArray);
                                            $saleproducts = array_unique($combinedArray);
                                        } else {
                                            $saleproducts = [];
                                        }
                                        if (auth('customers')->guest()) {
                                            $response = Cart::cart_list_cookie($request->all(), $store->id);
                                            $response = json_decode(json_encode($response));
                                        } else {
                                            $request->merge(['customer_id' => auth('customers')->user()->id, 'store_id' => $store->id, 'slug' => $slug]);
                                            $api = new ApiController();
                                            $data = $api->cart_list($request, $slug);
                                            $response = $data->getData();
                                        }
                                        $produt_id = [];
                                        foreach ($response->data->product_list as $item) {
                                            $produt_id[] = $item->product_id;
                                        }
                                        $produt_ids = array_map('intval', $produt_id);
                                        if (empty(array_diff($saleproducts, $produt_ids)) && empty(array_diff($produt_ids, $saleproducts)) == true) {
                                            return $this->error(['message' => __('This coupon has expired.')]);
                                        }
                                        if ($coupon->coupon_type == 'flat') {
                                            $price -= $amount;
                                        }
                                        if ($coupon->coupon_type == 'percentage') {
                                            if ($request->final_sub_total != null) {
                                                $sub_totals = $request->final_sub_total;
                                            } else {
                                                $sub_totals = $request->sub_total;
                                            }
                                            $amount = $amount * $sub_totals / 100;
                                            $price -= $amount;
                                        }
                                        if ($coupon->coupon_type == 'fixed product discount') {
                                            $coupon_applied = explode(',', ($coupon->applied_product));
                                            $exclude_product = explode(',', $coupon->exclude_product);
                                            $applied_categories = explode(',', $coupon->applied_categories);
                                            $exclude_categories = explode(',', $coupon->exclude_categories);
                                            $total_price = [];
                                            $quty = [];
                                            $product = [];

                                            foreach ($response->data->product_list as $item) {
                                                $product[] = $item->final_price;
                                            }
                                            $final_sub_total_sum = array_sum($product);

                                            foreach ($response->data->product_list as $item) {

                                                $quty[] = $item->qty;

                                                $cat = Product::where('id', $item->product_id)->where('store_id', $store->id)->pluck('category_id')->first();

                                                if ($coupon->sale_items != 0) {
                                                    $currentDate = Carbon::now()->toDateString();
                                                    $falsh_sale = FlashSale::where('store_id', $store->id)->where('is_active', 1)->where('start_date', '<=', $currentDate)->where('end_date', '>=', $currentDate)->get();
                                                    $saleEnableArray = [];
                                                    foreach ($falsh_sale as $sale) {
                                                        $saleEnableArray[] = json_decode($sale->sale_product, true);
                                                    }
                                                    $combinedArray = array_merge(...$saleEnableArray);
                                                    $saleproduct = array_unique($combinedArray);
                                                } else {
                                                    $saleproduct = [];
                                                }
                                                if ($applied_categories[0] !=  '' ||  $exclude_categories[0] !=  '') {
                                                    $common_cat = array_intersect($applied_categories, $exclude_categories);
                                                    if (in_array($cat, $common_cat)) {
                                                        $apply_product  = $item->final_price;
                                                        $apply_product -= 0;
                                                        $total_price[] = $apply_product;
                                                    } else {
                                                        if ($applied_categories[0] ==  ''  &&  $exclude_categories[0] !=  '') {
                                                            if ($exclude_categories[0] !=  '' && $applied_categories[0] ==  '' && $coupon_applied[0] ==  '') {
                                                                if (in_array($cat, $exclude_categories)) {
                                                                    $apply_product = $item->final_price;
                                                                    $apply_product -= 0;
                                                                    $total_price[] = $apply_product;
                                                                } else {
                                                                    if (in_array($item->product_id, $exclude_product)) {
                                                                        $apply_product = $item->final_price;
                                                                        $apply_product -= 0;
                                                                        $total_price[] = $apply_product;
                                                                    } else {
                                                                        if (in_array($item->product_id, $saleproduct)) {
                                                                            $apply_product  = $item->final_price;
                                                                            $apply_product -= 0;
                                                                            $total_price[] = $apply_product;
                                                                        } else {
                                                                            $apply_product = $item->final_price;
                                                                            $apply_product -= $amount * $item->qty;
                                                                            $total_price[] = $apply_product;
                                                                        }
                                                                    }
                                                                }
                                                            } else {
                                                                if (in_array($cat, $exclude_categories)) {
                                                                    if (in_array($item->product_id, $coupon_applied) == true) {

                                                                        $apply_product = $item->final_price;
                                                                        $apply_product -= 0;
                                                                        $total_price[] = $apply_product;
                                                                    } else {
                                                                        if (in_array($item->product_id, $coupon_applied) == true) {

                                                                            if (in_array($item->product_id, $saleproduct)) {
                                                                                $apply_product  = $item->final_price;
                                                                                $apply_product -= 0;
                                                                                $total_price[] = $apply_product;
                                                                            } else {
                                                                                $apply_product = $item->final_price;
                                                                                $apply_product -= $amount * $item->qty;
                                                                                $total_price[] = $apply_product;
                                                                            }
                                                                        } else {
                                                                            $apply_product = $item->final_price;
                                                                            $apply_product -= 0;
                                                                            $total_price[] = $apply_product;
                                                                        }
                                                                    }
                                                                } else {
                                                                    if (in_array($item->product_id, $coupon_applied) == true) {

                                                                        if (in_array($item->product_id, $saleproduct)) {
                                                                            $apply_product  = $item->final_price;
                                                                            $apply_product -= 0;
                                                                            $total_price[] = $apply_product;
                                                                        } else {
                                                                            $apply_product = $item->final_price;
                                                                            $apply_product -= $amount * $item->qty;
                                                                            $total_price[] = $apply_product;
                                                                        }
                                                                    } else {

                                                                        $apply_product = $item->final_price;
                                                                        $apply_product -= 0;
                                                                        $total_price[] = $apply_product;
                                                                    }
                                                                }
                                                            }
                                                        } else {

                                                            if (in_array($cat, $applied_categories)) {
                                                                // if exxlude product and applied_categories
                                                                if (in_array($item->product_id, $exclude_product) == true) {

                                                                    $apply_product  = $item->final_price;
                                                                    $apply_product -= 0;
                                                                    $total_price[] = $apply_product;
                                                                } else {
                                                                    if (in_array($cat, $applied_categories) && in_array($item->product_id, $coupon_applied)) {
                                                                        if (in_array($item->product_id, $saleproduct)) {
                                                                            $apply_product  = $item->final_price;
                                                                            $apply_product -= 0;
                                                                            $total_price[] = $apply_product;
                                                                        } else {
                                                                            $apply_product = $item->final_price;
                                                                            $apply_product -= $amount * $item->qty;
                                                                            $total_price[] = $apply_product;
                                                                        }
                                                                    } else {
                                                                        $apply_product  = $item->final_price;
                                                                        $apply_product -= 0;
                                                                        $total_price[] = $apply_product;
                                                                    }
                                                                }
                                                            } else {
                                                                // if not this product catgory in  applied_categories but product in  coupon_applied
                                                                $apply_product  = $item->final_price;
                                                                $apply_product -= 0;
                                                                $total_price[] = $apply_product;
                                                            }
                                                        }
                                                    }

                                                    $price = array_sum($total_price);
                                                    $discount_amounts = $final_sub_total_sum - $price;

                                                } else {
                                                    if ($coupon_applied[0] ==  '' &&  $exclude_product[0] ==  '') {
                                                        if (in_array($item->product_id, $saleproduct)) {
                                                            $apply_product  = $item->final_price;
                                                            $apply_product -= 0;
                                                            $total_price[] = $apply_product;
                                                        } else {
                                                            if (in_array($item->product_id, $saleproduct)) {
                                                                $apply_product  = $item->final_price;
                                                                $apply_product -= 0;
                                                                $total_price[] = $apply_product;
                                                            } else {
                                                                $apply_product = $item->final_price;
                                                                $apply_product -= $amount * $item->qty;
                                                                $total_price[] = $apply_product;
                                                            }
                                                        }

                                                        $price = array_sum($total_price);
                                                        $discount_amounts = $final_sub_total_sum - $price;
                                                    } else {

                                                        if ($coupon_applied[0] ==  '') {
                                                            if (in_array($item->product_id, $exclude_product)) {
                                                                $apply_product  = $item->final_price;
                                                                $apply_product -= 0;
                                                                $total_price[] = $apply_product;
                                                            } else {
                                                                if (in_array($item->product_id, $saleproduct)) {
                                                                    $apply_product  = $item->final_price;
                                                                    $apply_product -= 0;
                                                                    $total_price[] = $apply_product;
                                                                } else {
                                                                    $apply_product = $item->final_price;
                                                                    $apply_product -= $amount * $item->qty;
                                                                    $total_price[] = $apply_product;
                                                                }
                                                            }
                                                        } else {

                                                            $common_values = array_intersect($coupon_applied, $exclude_product);

                                                            if (in_array($item->product_id, $coupon_applied)) {

                                                                if (in_array($item->product_id, $common_values)) {
                                                                    $apply_product  = $item->final_price;
                                                                    $apply_product  -= 0;
                                                                    $total_price[] = $apply_product;
                                                                } else {

                                                                    if (in_array($item->product_id, $saleproduct)) {
                                                                        $apply_product  = $item->final_price;
                                                                        $apply_product -= 0;
                                                                        $total_price[] = $apply_product;
                                                                    } else {
                                                                        $apply_product = $item->final_price;
                                                                        $apply_product -= $amount * $item->qty;
                                                                        $total_price[] = $apply_product;
                                                                    }
                                                                }
                                                            } else {

                                                                $apply_product  = $item->final_price;
                                                                $apply_product -= 0;
                                                                $total_price[] = $apply_product;
                                                            }
                                                        }

                                                        $price = array_sum($total_price);
                                                        $discount_amounts = $final_sub_total_sum - $price;
                                                    }
                                                }
                                            }

                                            if ($coupon->coupon_limit_x_item != null) {
                                                $intArray = array_map('intval', $quty);
                                                $sum = array_sum($intArray);
                                                $total_amount  = $discount_amounts / $sum;
                                                if ($sum  >= $coupon->coupon_limit_x_item) {

                                                    $discount_amounts =  $total_amount * $coupon->coupon_limit_x_item;
                                                } else {

                                                    $discount_amounts =  $total_amount *  $sum;
                                                }
                                            }
                                            if ($coupon->discount_amount != 0 && $discount_amounts == 0) {
                                                return $this->error(['message' => __(' Sorry, this coupon is not applicable to selected products.')]);
                                            }
                                        }
                                    } else {
                                        return $this->error(['message' => ' The minimum spend for this coupon is ' . (currency_format_with_sym($coupon->minimum_spend, $store->id) ?? SetNumberFormat($coupon->minimum_spend)) . '.']);
                                    }
                                } else {
                                    return $this->error(['message' => ' The maximum spend for this coupon is ' . (currency_format_with_sym($coupon->maximum_spend, $store->id) ?? SetNumberFormat($coupon->maximum_spend)) . '.']);
                                }

                                $coupon_array['message'] = __('Coupon is valid.');
                                $coupon_array['id'] = $coupon->id;
                                $coupon_array['name'] = $coupon->coupon_name;
                                $coupon_array['code'] = $coupon->coupon_code;
                                $coupon_array['coupon_discount_type'] = $coupon->coupon_type;
                                if ($coupon->coupon_type == 'fixed product discount') {

                                    $coupon_array['coupon_discount_amount'] = $discount_amounts;
                                } else {
                                    $coupon_array['coupon_discount_amount'] = $coupon->discount_amount;
                                }
                                $coupon_array['coupon_end'] = '----------------------';
                                $coupon_array['original_price'] = SetNumber($request->final_sub_total);
                                $coupon_array['final_price'] = SetNumber($price);
                                $coupon_array['discount_price'] = SetNumber($price);
                                if ($coupon->coupon_type == 'fixed product discount') {
                                    $coupon_array['amount'] = SetNumber($discount_amounts);
                                    $coupon_array['discount_amount_currency'] = currency_format_with_sym($discount_amounts, $store->id) ?? SetNumberFormat($discount_amounts);
                                } else {
                                    $coupon_array['amount'] = SetNumber($amount);
                                    $coupon_array['discount_amount_currency'] = currency_format_with_sym($amount, $store->id) ?? SetNumberFormat($amount);
                                }
                                $coupon_array['shipping_total_price'] = currency_format_with_sym($price, $store->id) ?? SetNumberFormat($price);
                            } else {

                                $amount = 0;
                                $coupon_array['message'] = __('Coupon is valid.');
                                $coupon_array['id'] = $coupon->id;
                                $coupon_array['name'] = $coupon->coupon_name;
                                $coupon_array['code'] = $coupon->coupon_code;
                                $coupon_array['coupon_discount_type'] = $coupon->coupon_type;
                                $coupon_array['coupon_discount_amount'] = 0;
                                $coupon_array['coupon_end'] = '----------------------';
                                $coupon_array['original_price'] = SetNumber($request->sub_total);
                                $coupon_array['final_price'] = SetNumber(0);
                                $coupon_array['discount_price'] = SetNumber(0);
                                $coupon_array['amount'] = SetNumber(0);
                                $coupon_array['discount_amount_currency'] = currency_format_with_sym(0, $store->id) ?? SetNumberFormat(0);
                                $coupon_array['shipping_total_price'] = currency_format_with_sym(0, $store->id) ?? SetNumberFormat(0);
                            }
                        } else {
                            $amount = 0;
                            $discount_amounts = 0;
                            $coupon_array['message'] = __('Coupon is valid.');
                            $coupon_array['id'] = $coupon->id;
                            $coupon_array['name'] = $coupon->coupon_name;
                            $coupon_array['code'] = $coupon->coupon_code;
                            $coupon_array['coupon_discount_type'] = $coupon->coupon_type;
                            $coupon_array['coupon_discount_amount'] = 0;
                            $coupon_array['coupon_end'] = '----------------------';
                            $coupon_array['original_price'] = SetNumber($request->sub_total);
                            $coupon_array['final_price'] = SetNumber(0);
                            $coupon_array['discount_price'] = SetNumber(0);
                            $coupon_array['amount'] = SetNumber(0);
                            $coupon_array['discount_amount_currency'] = currency_format_with_sym(0, $store->id) ?? SetNumberFormat(0);
                            $coupon_array['shipping_total_price'] = currency_format_with_sym(0, $store->id) ?? SetNumberFormat(0);
                        }
                    }
                } else {
                    $price = $request->final_sub_total;

                    $amount = $coupon->discount_amount;
                    if ($request->final_sub_total != null) {

                        $sub_total_min = $request->final_sub_total;
                    } else {

                        $sub_total_min = $request->sub_total;
                    }
                    if ($coupon->sale_items != 0) {
                        $currentDate = Carbon::now()->toDateString();
                        $falsh_sale = FlashSale::where('store_id', $store->id)->where('is_active', 1)->where('start_date', '<=', $currentDate)->where('end_date', '>=', $currentDate)->get();
                        $saleEnableArray = [];
                        foreach ($falsh_sale as $sale) {
                            $saleEnableArray[] = json_decode($sale->sale_product, true);
                        }
                        $combinedArray = array_merge(...$saleEnableArray);
                        $saleproducts = array_unique($combinedArray);
                    } else {
                        $saleproducts = [];
                    }
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

                    $coupon_array['message'] = __('Coupon is valid.');
                    $coupon_array['id'] = $coupon->id;
                    $coupon_array['name'] = $coupon->coupon_name;
                    $coupon_array['code'] = $coupon->coupon_code;
                    $coupon_array['coupon_discount_type'] = $coupon->coupon_type;
                    $coupon_array['tax_price'] = $response->data->tax_price ?? 0;
                    if ($coupon->coupon_type == 'fixed product discount') {

                        $coupon_array['coupon_discount_amount'] = $response->data->total_coupon_price ?? 0;
                    } else {
                        $coupon_array['coupon_discount_amount'] = $response->data->total_coupon_price ?? 0;
                    }
                    $coupon_array['coupon_end'] = '----------------------';
                    $coupon_array['original_price'] = SetNumber($request->final_sub_total);
                    $coupon_array['final_price'] = SetNumber(($response->data->final_price - $response->data->total_coupon_price) ?? 0);
                    $coupon_array['discount_price'] = SetNumber($response->data->total_coupon_price ?? 0);
                    if ($coupon->coupon_type == 'fixed product discount') {
                        $coupon_array['amount'] = SetNumber($response->data->total_coupon_price ?? 0);
                        $coupon_array['discount_amount_currency'] = currency_format_with_sym(($response->data->total_coupon_price ?? 0), $store->id) ?? SetNumberFormat($response->data->total_coupon_price ?? 0);
                    } else {
                        $coupon_array['amount'] = $response->data->total_coupon_price ?? 0;
                        $coupon_array['discount_amount_currency'] = currency_format_with_sym(($response->data->total_coupon_price ?? 0), $store->id) ?? SetNumberFormat($response->data->total_coupon_price ?? 0);
                    }
                    $coupon_array['shipping_total_price'] = currency_format_with_sym(($response->data->total_sub_price ?? 0), $store->id) ?? SetNumberFormat($response->data->total_sub_price ?? 0);
                }
            }

            if ($coupon->coupon_type == 'fixed product discount') {
                //session()->put( 'coupon_prices', $discount_amounts );
                $request->merge(['total_coupon_amount' => $discount_amounts ?? 0]);
            } else {
                //session()->put( 'coupon_prices', $amount );
                $request->merge(['total_coupon_amount' => $amount]);
            }
            $coupon_array['shipping_method'] = !empty($shipping_Methods) ? $shipping_Methods : '';
            $coupon_array['CURRENCY'] = $CURRENCY;
            if (is_array($response->data->product_list)) {
                $total_products = count($response->data->product_list);
            } elseif (is_object($response->data->product_list)) {
                $total_products = count(get_object_vars($response->data->product_list));
            } else {
                $total_products = 1;
            }

            $coupon_array['product_list'] = [];
            $coupon_array['total_tax_price'] = SetNumber($response->data->tax_price ?? 0);
            $coupon_array['tax_price'] = SetNumber($response->data->tax_price ?? 0);
            $coupon_array['tax_id'] = $response->data->tax_id ?? 0;
            $coupon_array['tax_rate'] = $response->data->tax_rate ?? 0;
            $coupon_array['tax_type'] = 'Percentage';
            $coupon_array['tax_name'] = $response->data->tax_name ?? 'Tax';
            $coupon_array['cart_total_product'] = $total_products;
            $coupon_array['cart_total_qty'] = $response->data->cart_total_qty;
            $coupon_array['original_price'] = SetNumber($response->data->final_price);
            $coupon_array['total_final_price'] =  SetNumber(($response->data->final_price - $response->data->total_coupon_price) ?? 0);
            $coupon_array['final_price'] =  SetNumber(($response->data->final_price - $response->data->total_coupon_price) ?? 0);
            $coupon_array['total_sub_price'] = (float) $coupon_array['shipping_total_price'] ?? 0;
            $coupon_array['sub_total'] = SetNumber($price);
            $coupon_array['total_coupon_price'] = SetNumber($response->data->coupon_discount_amount ?? 0);
            $coupon_array['shipping_original_price'] = (float)  $coupon_array['shipping_total_price'] ?? 0;
            $coupon_array['coupon_code'] =  $coupon->coupon_code ?? 'Code';
            if (isset($request->coupon_code)) {
                $coupon = Coupon::whereRaw('BINARY `coupon_code` = ?', [$request->coupon_code])->first();
            }
            $coupon_array['coupon_info'] = [];
            $coupon_array['id'] = $coupon->id;
            $coupon_array['coupon_info']['coupon_id'] = $coupon->id ?? 0;
            $coupon_array['coupon_info']['coupon_name'] = $coupon->coupon_name ?? '-';
            $coupon_array['coupon_info']['coupon_code'] = $coupon->coupon_code ?? '-';
            $coupon_array['coupon_info']['coupon_discount_type'] = $coupon->coupon_type ?? 'percentage';
            $coupon_array['coupon_info']['coupon_discount_number'] = SetNumber($coupon->discount_amount ?? 0);
            $coupon_array['coupon_info']['coupon_discount_amount'] = $coupon_array['coupon_discount_amount'];
            $coupon_array['coupon_info']['coupon_final_amount'] =  SetNumber($response->data->coupon_discount_amount ?? 0);
            return $this->success($coupon_array, __('Coupon code applied successfully.'));
        }
        return $this->error(['message' => __('Invalid coupon code.')], __('Invalid coupon code.'));
    }

    public function update_address(Request $request, $slug = '')
    {
        $store = getStore($slug);
        
        $rules = [
            'address_id' => 'required',
            'customer_id' => 'required',
            'title' => 'required',
            'address' => 'required',
            'country' => 'required',
            'state' => 'required',
            'city' => 'required',
            'postcode' => 'required',
            'default_address' => 'required',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $customer = Customer::find($request->customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $default_address = !empty($request->default_address) ? 1 : 0;

        $DeliveryAddress = DeliveryAddress::find($request->address_id);
        if (!empty($DeliveryAddress)) {
            $DeliveryAddress->title = $request->title;
            $DeliveryAddress->country_id = $request->country;
            $DeliveryAddress->state_id = $request->state;
            $DeliveryAddress->city_id = $request->city;
            $DeliveryAddress->customer_id = $request->customer_id;
            $DeliveryAddress->title = $request->title;
            $DeliveryAddress->address = $request->address;
            $DeliveryAddress->postcode = $request->postcode;
            $DeliveryAddress->default_address = $default_address;
            $DeliveryAddress->save();

            if ($default_address == 1) {
                $u_a_a['default_address'] = 0;
                DeliveryAddress::where('customer_id', $request->customer_id)->where('id', '!=', $request->address_id)->update($u_a_a);
            }

            return $this->success(['message' => __('Address update successfully.')], __('Address update successfully.'));
        } else {
            return $this->error(['message' => __('Address not found.')], __('Address not found.'));
        }
    }

    public function base_url(Request $request, $slug = '')
    {
        $img_url = get_file('themes');
        $data =  explode('themes', $img_url);

        return $this->success(['base_url' => url('/api/' . $slug), 'image_url' => $data[0], 'payment_url' => url('/')], __('Base url get successfully.'));
    }

    public function currency(Request $request, $slug)
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $store_id = !empty($store) ? $store->id  : $request->store_id;

        $array['currency'] = \App\Models\Utility::GetValByName('CURRENCY', $store_id) ?? '$';
        $array['currency_name'] = \App\Models\Utility::GetValByName('CURRENCY_NAME', $store_id) ?? 'USD';
        return $this->success($array, __('Currency get successfully.'));
    }

    public function main_category(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $Category = Category::where('store_id', $store->id)->OrderBy('id', 'desc')->get()->toArray();

        $max_price = 0;
        $max_price_product_data = Product::where('store_id', $store->id)->where('product_type', null)->OrderBy('price', 'DESC')->first();
        $max_price_product = !empty($max_price_product_data) ? $max_price_product_data->price : 0;
        $max_price = $max_price_product;

        $max_price_product_varint_data = ProductVariant::where('store_id', $store->id)->OrderBy('price', 'DESC')->first();
        $max_price_product_varint = !empty($max_price_product_varint_data) ? $max_price_product_varint_data->price : 0;
        if ($max_price_product_varint > $max_price_product) {
            $max_price = $max_price_product_varint;
        }

        if (!empty($Category)) {
            return $this->success($Category, __('Tag list get successfully.'), 200, '', $max_price);
        } else {
            return $this->error(['message' => __('Tag not found.')], __('Tag not found.'));
        }
    }

    public function search(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        // Category Search
        if ($request->type == 'product_search' && !empty($request->name)) {
            $product_query = Product::where('store_id', $store->id)->Where('name', 'like',  '%' . $request->name . '%');
            $Data = $product_query->paginate(10);
        }

        // Product Search
        if ($request->type == 'product_filter') {
            $product_query = Product::where('store_id', $store->id);

            if (!empty($request->name)) {
                $product_query->Where('name', 'like',  '%' . $request->name . '%');
            }

            if (!empty($request->tag)) {
                $product_query->whereIn('category_id', explode(',', $request->tag));
            }

            if (!empty($request->rating)) {
                $product_query->whereRaw('ROUND(average_rating) =' . $request->rating);
            }

            if ($request->min_price != '' && $request->max_price != '') {
                $products_variant_id = Product::where('variant_product', 1)->pluck('id')->toArray();
                $ProductStock = [];
                if (count($products_variant_id) > 0) {
                    $ProductStock = ProductVariant::whereIn('product_id', $products_variant_id)->whereBetween('price', [$request->min_price, $request->max_price])->pluck('product_id')->toArray();
                }
                $products_no_variant = Product::where('variant_product', 0)->whereBetween('price', [$request->min_price, $request->max_price])->pluck('id')->merge($ProductStock)->toArray();

                if (count($products_no_variant) > 0) {
                    $product_query->whereIn('id', $products_no_variant);
                }
            }
            $Data = $product_query->paginate(10);
        }

        if (!empty($Data)) {
            return $this->success($Data, __('Product list get successfully.'));
        } else {
            return $this->error(['message' => __('Product not found.')], __('Product not found.'));
        }
    }

    public function categorys_product(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $category_id = $request->category_id;
        if (auth('customers')->user()) {
            $cart = Cart::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->count();
        } else {
            $cart = 0;
        }
        $product_query = Product::select('id', 'name', 'slug', 'tag_id', 'category_id', 'tax_id', 'tax_status', 'shipping_id', 'preview_type', 'preview_video', 'preview_content', 'trending', 'status', 'video_url', 'track_stock', 'stock_order_status', 'price', 'sale_price', 'product_stock', 'low_stock_threshold', 'downloadable_product', 'product_weight', 'cover_image_path', 'cover_image_url', 'stock_status', 'variant_product', 'attribute_id', 'product_attribute', 'custom_field_status', 'custom_field', 'description', 'detail', 'specification', 'average_rating', 'store_id')
            ->where('store_id', $store->id);

        if (!empty($category_id)) {
            $product_query->where('category_id', $category_id);
        }
        $products = $product_query->paginate(10);
        $data = $products;
        if (!empty($data)) {
            return $this->success($data, __('Category wise product list get successfully.'), 200, $cart);
        } else {
            return $this->error(['message' => __('Product not found.')], __('Product not found.'), 200, $cart);
        }
    }

    public function categorys_product_guest(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $category_id = $request->category_id;

        $product_query = Product::select('id', 'name', 'slug', 'tag_id', 'category_id', 'tax_id', 'tax_status', 'shipping_id', 'preview_type', 'preview_video', 'preview_content', 'trending', 'status', 'video_url', 'track_stock', 'stock_order_status', 'price', 'sale_price', 'product_stock', 'low_stock_threshold', 'downloadable_product', 'product_weight', 'cover_image_path', 'cover_image_url', 'stock_status', 'variant_product', 'attribute_id', 'product_attribute', 'custom_field_status', 'custom_field', 'description', 'detail', 'specification', 'average_rating', 'store_id')
            
            ->where('store_id', $store->id);

        if (!empty($category_id)) {
            $product_query->where('category_id', $category_id);
        }

        $products = $product_query->paginate(10);
        $data = $products;
        if (!empty($data)) {
            return $this->success($data, __('Category wise product list get successfully.'));
        } else {
            return $this->error(['message' => __('Product not found.')], __('Product not found.'));
        }
    }

    public function product_detail(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        if (auth('customers')->user()) {
            $cart = Cart::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->count();
        } else {
            $cart = 0;
        }
        $id = $request->id;
        $data = [];
        if (!empty($id)) {
            $product = Product::where('id', $id)->first();
            if (!empty($product)) {
                $product = $product->toArray();
                $data['product_info'] =  $product;

                $data['product_image'] = [];
                // Product Image
                $productImage = ProductImage::where('product_id', $id)->get()->toArray();
                if (!empty($productImage)) {
                    $data['product_image'] =  $productImage;
                }

                $data['product_Review'] = [];
                // Product review
                $review_array = [];
                $productReviews = Testimonial::where('product_id', $id)->get();
                if (!empty($productReviews)) {
                    foreach ($productReviews as $key => $Review) {
                        $rating_word = 'poor';
                        if ($Review->rating_no == 5) {
                            $rating_word = 'Very Good';
                        }
                        if ($Review->rating_no == 4) {
                            $rating_word = 'Good';
                        }
                        if ($Review->rating_no == 3) {
                            $rating_word = 'Average';
                        }
                        if ($Review->rating_no == 2) {
                            $rating_word = 'Bad';
                        }
                        if ($Review->rating_no == 1) {
                            $rating_word = 'Very bad';
                        }


                        $review_array[$key]['product_image'] = !empty($Review->ProductData->cover_image_path) ? $Review->ProductData->cover_image_path : 'assets/img/image_placholder.png';
                        $review_array[$key]['title'] = $Review->title;
                        $review_array[$key]['sub_title'] = $rating_word;
                        $review_array[$key]['rating'] = $Review->rating_no;
                        $review_array[$key]['review'] = $Review->description;
                        $review_array[$key]['user_image'] = !empty($Review->UserData->profile_image) ? $Review->UserData->profile_image : 'public/assets/img/user_profile.webp';
                        $review_array[$key]['user_name'] = !empty($Review->UserData->name) ? $Review->UserData->name : '';
                        $review_array[$key]['user_email'] = !empty($Review->UserData->email) ? $Review->UserData->email : '';
                    }
                    $data['product_Review'] = $review_array;
                }
                if ($product['variant_product'] == 1) {
                    if (!empty($product['product_attribute'])) {
                        $variant_data = [];
                        $variant = json_decode($product['product_attribute']);
                        if (!empty($product->DefaultVariantData->variant)) {
                            $variant_name_array = explode('-', $product->DefaultVariantData->variant);
                        }

                        foreach ($variant as $value) {
                            $p_variant = Utility::ProductAttribute($value->attribute_id);
                            $attribute = json_decode($p_variant);
                            $variant_array = [];
                            $values_datas = $value->values;

                            $variant_datas = ProductAttributeOption::where('attribute_id', $attribute->id)->get();

                            foreach ($values_datas as $value) {
                                $valueIds = explode('|', $value);
                                foreach ($valueIds as $valueId) {
                                    $valueId = intval($valueId);
                                    $option_data = ProductAttributeOption::find($valueId);
                                    $variant_array[] = [
                                        'id' => $valueId ?? '',
                                        'name' => $option_data->terms ?? ''
                                    ];
                                }
                            }

                            $option[] = [
                                'variant_name' => $attribute->name,
                                'variant_list_data' => $variant_array
                            ];
                        }
                    }
                    $data['variant'] = $option;
                }
            }
            $data['releted_products'] = [];
            if ($product) {
                $releted_product_query = Product::where('store_id', $store->id)->where('product_type', null)->where('id', '!=', $product['id']);
                if ($product['category_id']) {
                    $releted_product_query->where('category_id', $product['category_id']);
                }
                $data['releted_products'] = $releted_product_query->orderBy('id', 'DESC')->take(20)->get();
            }
        }

        $data['product_instruction'] = Product::instruction_array( $store->id);



        if (!empty($data)) {
            return $this->success($data, __("Product detail get successfully."), 200, $cart);
        } else {
            return $this->error(['message' => __('Product not found.')], __('Product not found.'), 200, 0, $cart);
        }
    }

    public function product_detail_guest(Request $request, $slug = '')
    {
        $store = getStore($slug);
        

        $cart = 0;
        $id = $request->id;
        $data = [];
        if (!empty($id)) {
            $product = Product::where('id', $id)->first();
            if (!empty($product)) {
                $product = $product->toArray();
                $data['product_info'] =  $product;

                $data['product_image'] = [];
                // Product Image
                $productImage = ProductImage::where('product_id', $id)->get()->toArray();
                if (!empty($productImage)) {
                    $data['product_image'] =  $productImage;
                }

                $data['product_Review'] = [];
                // Product review
                $review_array = [];
                $productReviews = Testimonial::where('product_id', $id)->get();
                if (!empty($productReviews)) {
                    foreach ($productReviews as $key => $Review) {
                        $rating_word = 'poor';
                        if ($Review->rating_no == 5) {
                            $rating_word = 'Very Good';
                        }
                        if ($Review->rating_no == 4) {
                            $rating_word = 'Good';
                        }
                        if ($Review->rating_no == 3) {
                            $rating_word = 'Average';
                        }
                        if ($Review->rating_no == 2) {
                            $rating_word = 'Bad';
                        }
                        if ($Review->rating_no == 1) {
                            $rating_word = 'Very bad';
                        }


                        $review_array[$key]['product_image'] = !empty($Review->ProductData->cover_image_path) ? $Review->ProductData->cover_image_path : 'assets/img/image_placholder.png';
                        $review_array[$key]['title'] = $Review->title;
                        $review_array[$key]['sub_title'] = $rating_word;
                        $review_array[$key]['rating'] = $Review->rating_no;
                        $review_array[$key]['review'] = $Review->description;
                        $review_array[$key]['user_image'] = 'public/assets/img/user_profile.webp';
                        $review_array[$key]['user_name'] = !empty($Review->UserData->name) ? $Review->UserData->name : '';
                        $review_array[$key]['user_email'] = !empty($Review->UserData->email) ? $Review->UserData->email : '';
                    }
                    $data['product_Review'] = $review_array;
                }

                $data['variant'] = [];
                $data['product_varint'] = [];
                if ($product['variant_product'] == 1) {
                    // Product Varint Array
                    if (!empty($product['variant_attribute'])) {
                        $variant_array = [];
                        $variant_attribute = json_decode($product['variant_attribute'], true);
                        foreach ($variant_attribute as $key => $variant) {
                            $variant_data = Product::VariantAttribute($variant['attribute_id']);

                            $variant_array[$key]['name'] = (!empty($variant_data)) ? $variant_data->name : '';
                            $variant_array[$key]['type'] = (!empty($variant_data)) ? $variant_data->type : '';
                            $variant_array[$key]['value'] = $variant['values'];
                        }
                        $data['variant'] = $variant_array;
                    }
                }
            }
        }

        $data['product_instruction'] = Product::instruction_array($store->id);

        if (!empty($data)) {
            return $this->success($data, __("Product detail get successfully."), 200, $cart);
        } else {
            return $this->error(['message' => __('Product not found.')], __("Product not found."), 200, 0, $cart);
        }
    }

    public function product_rating(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $rules = [
            'id' => 'required',
            'user_id' => 'required',
            'rating_no' => 'required',
            'title' => 'required',
            'description' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        $Product = Product::find($request->id);
        if (empty($Product)) {
            return $this->error([
                'message' => 'Product not found.'
            ], __("Product not found."));
        }


        $is_Review = Testimonial::where('user_id', $request->user_id)->where('product_id', $request->id)->where('store_id', $store->id)->exists();

        if ($is_Review) {
            return $this->error([
                'message' => __('Rating already added.')
            ], __("Rating already added."));
        }


        $review = new Testimonial();
        $review->user_id        = $request->user_id;
        $review->category_id    = $Product->category_id;
        $review->product_id     = $request->id;
        $review->rating_no      = $request->rating_no;
        $review->title          = $request->title;
        $review->description    = $request->description;
        $review->status         = 1;
        $review->store_id       = $store->id;
        $review->save();

        Testimonial::AvregeRating($request->id);

        return $this->success([
            'message' => __('Rating Add successfully.')
        ], __("Rating add successfully."));
    }

    public function random_review(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $limit = !empty($request->limit) ? $request->limit : 10;
        $review = Testimonial::limit($limit)->get();

        if (!empty($review)) {
            return $this->success($review, __("Review get successfully."));
        } else {
            return $this->error(['message' => __('Review not found.')], __("Review not found."));
        }
    }

    public function landingpage(Request $request, $slug = '')
    {
        $store = getStore($slug);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        


        $setting_json = AppSetting::select('theme_json_api')->where('page_name', 'main')->where('store_id', $store->id)->first();
        $limit = !empty($request->limit) ? $request->limit : 10;
        $review = Testimonial::with([
            'UserData:id,profile_image,name',
            'ProductData:id,name,cover_image_path'
        ])->where('store_id', $store->id)->limit($limit)->get();
        $loyality_program_enabled = \App\Models\Utility::GetValByName('loyality_program_enabled', $store->id);
        $loyality_program_enabled = empty($loyality_program_enabled) || $loyality_program_enabled == 'on' ? 'on' : 'off';
        if (auth('customers')->user()) {
            $cart = Cart::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->count();
        } else {
            $cart = 0;
        }

        if (!empty($setting_json->theme_json_api)) {

            return $this->success(['them_json' => json_decode($setting_json->theme_json_api, true), 'loyality_section' => $loyality_program_enabled, 'reviews' => $review], __("Homepage details get successfully."), 200, $cart);
        } else {
            $homepage_json_path = base_path('themes/' . $store->theme_id . '/theme_json/homepage.json');
            if (file_exists($homepage_json_path)) {
                $homepage_json = json_decode(file_get_contents($homepage_json_path), true);
                $homepage_array = [];
                foreach ($homepage_json as $key => $value) {
                    foreach ($value['inner-list'] as $key1 => $val) {
                        $homepage_array[$value['section_slug']][$val['field_slug']] = $val['field_default_text'];
                    }
                }
                return $this->success(['them_json' => $homepage_array, 'loyality_section' => $loyality_program_enabled, 'reviews' => $review], __("Homepage details get successfully."), 200, $cart);
            } else {
                return $this->error(['message' => __('Theme not found.')], __("Theme not found."));
            }
        }
    }

    public function product_banner(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $setting_json = AppSetting::select('theme_json_api')->where('page_name', 'product_banner')->where('store_id', $store->id)->first();
        $product_banner_default_path = base_path('theme_json/product-banner.json');
        if (!empty($setting_json->theme_json_api)) {
            return $this->success(['them_json' => json_decode($setting_json->theme_json_api, true)], __("Product banner details get successfully."));
        } else {
            $product_banner_json_path = base_path('themes/' . $store->theme_id . '/theme_json/product-banner.json');
            if (file_exists($product_banner_json_path)) {
                $product_banner_json = json_decode(file_get_contents($product_banner_json_path), true);
                $product_banner_array = [];
                foreach ($product_banner_json as $key => $value) {
                    foreach ($value['inner-list'] as $key1 => $val) {
                        $product_banner_array[$value['section_slug']][$val['field_slug']] = $val['field_default_text'];
                    }
                }
                return $this->success(['them_json' => $product_banner_array], __("Product banner details get successfully."));
            } elseif (file_exists($product_banner_default_path)) {
                $product_banner_json = json_decode(file_get_contents($product_banner_default_path), true);
                $product_banner_array = [];
                foreach ($product_banner_json as $key => $value) {
                    foreach ($value['inner-list'] as $key1 => $val) {
                        $product_banner_array[$value['section_slug']][$val['field_slug']] = $val['field_default_text'];
                    }
                }
                return $this->success(['them_json' => $product_banner_array], __("Product banner details get successfully."));
            } else {
                return $this->error(['message' => __('Product bannet not found.')], __('Product bannet not found.'));
            }
        }
    }

    public function category(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $Category = Category::selectRaw("categories.*, (SELECT COUNT(*) FROM products WHERE products.category_id = categories.id) as product_count")
            ->where('store_id', $store->id)->get()->toArray();
        
        if (!empty($Category)) {
            return $this->success($Category, __('Category get successfully.'));
        } else {
            return $this->error(['message' => __('Category not found.')], __('Category not found.'));
        }
    }

    public function delivery_list(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $shipping = ShippingZone::with('shipping_methods')->where('store_id', $store->id)->first();
        if (!empty($shipping)) {
            return $this->success($shipping->shipping_methods, __('Shipping list get successfully.'));
        } else {
            return $this->error(['message' => __('Shipping not found.')], __('Shipping not found.'));
        }
    }

    public function delivery_charge(Request $request, $slug = '')
    {
        $store = getStore($slug);
            if (empty($store)) {
                return $this->error(['message' => __('Store not found.')], __("Store not found."));
            }
        

        $rules = [
            'method_id' => 'required',
            'country_id' => 'required',
            'state_id' => 'required',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        if (!auth('customers')->user()) {
            $customer_id = $request->customer_id ?? 0;
            $cart = [];
        } else {
            $customer_id = auth('customers')->user()->id;
            $cart = Cart::where('customer_id', $customer_id)->where('store_id', $store->id)->get();
        }
        $customer = Customer::find($customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $ProductVariant_array = [];
        $shipping_method = ShippingMethod::find($request->method_id);
        $price = 0;
        if (!empty($shipping_method)) {
            foreach ($cart as $key => $Product) {
                $productId = $Product->product_id;
                $product_data = Product::find($productId);
                $price = $product_data->sale_price;

                if ($product_data->variant_product == 0) {
                    $product_price = $product_data->sale_price;
                } else {
                    $productVariants = [];

                    foreach ($cart as $item) {
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
                    $product_stock = ProductVariant::whereIn('id', $uniqueVariantIds)
                        ->where('product_id', $Product->product_id)
                        ->get();
                    $product_price = 0;
                    foreach ($product_stock as $variant) {
                        $product_price += $variant->price;
                    }
                }
                if ($shipping_method->method_name == 'Flat Rate' && $Product) {
                    $charge_amount = $this->calculateFlaterate($shipping_method, $shipping_method->cost, $Product);

                    $total_shipping_price = $charge_amount;
                } elseif ($shipping_method->method_name == 'Local pickup') {
                    $cost_totle =  $shipping_method->cost;

                    $total_shipping_price = $cost_totle;
                } else {
                    $cost_totle = $charge_amount =  $shipping_method->cost;

                    $total_shipping_price = $cost_totle;
                }
            }

            $price += $total_shipping_price ?? 0;
            $ProductVariant_array['original_price'] = SetNumber($product_price ?? 0);
            $ProductVariant_array['charge_price'] = SetNumber($total_shipping_price ?? 0);
            $ProductVariant_array['final_price'] = SetNumber($price ?? 0);
        }
        if (!empty($ProductVariant_array)) {
            return $this->success($ProductVariant_array, __('Shipping charge calculate successfully.'));
        } else {
            return $this->error(['message' => __('Shipping not found.')], __('Shipping not found.'));
        }
    }

    public  function calculateFlaterate($shippingMethods, $shippingCost, $Product)
    {
        $cost_totle =  $shippingCost;
        $price = 0;
        $productId = $Product->product_id;
        $product_data = Product::find($productId);
        if ($product_data->variant_product == 0) {
            if ($shippingMethods['product_cost'] != null) {
                $shippingClass = Shipping::find($product_data->shipping_id);
                $value = $shippingMethods['product_cost'];
                $product_cost = json_decode($value, true);
                if ($shippingClass == null) {
                    $price  += $product_cost['product_no_cost'];
                } else {
                    foreach ($product_cost['product_cost'] as $key => $value) {
                        if ($key == $shippingClass->id) {
                            $price  += $value;
                        } else {
                            $price  += 0;
                        }
                    }
                }
            } else {
                $cost_totle = $shippingMethods->cost;
            }
            $product_price = $product_data->sale_price;
        } else {
            if ($shippingMethods['product_cost'] != null) {
                $productVariants = [];
                if (isset($Product)) {
                    $productId = $Product->product_id;
                    $variantId = $Product->variant_id;

                    if (!isset($productVariants[$productId])) {
                        $productVariants[$productId] = [];
                    }
                    $productVariants[$productId][] = $variantId;

                    $uniqueVariantIds = [];
                    foreach ($productVariants as $variants) {
                        $uniqueVariantIds = array_merge($uniqueVariantIds, $variants);
                    }

                    $uniqueVariantIds = array_values(array_unique($uniqueVariantIds));
                    $product_stock = ProductVariant::whereIn('id', $uniqueVariantIds)->where('product_id', $Product->product_id)->get();
                    $value = $shippingMethods['product_cost'];
                    $product_cost = json_decode($value, true);

                    foreach ($product_stock as $stock) {
                        $shippingClass = Shipping::find($stock->shipping);
                        $product_price = $stock->price;
                        if ($stock->shipping == 'same_as_parent') {
                            $shipping = Shipping::find($product_data->shipping_id);
                            if ($shipping == null) {
                                $price  += $product_cost['product_no_cost'];
                            }
                            foreach ($product_cost['product_cost'] as $key => $value) {
                                if ($shipping) {
                                    if ($key == $shipping->id) {
                                        $price  += $value;
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
        return $cost_totle + $price;
    }

    public function country_list(Request $request, $slug = '')
    {
        $store = getStore($slug);
        

        $countrys = country::select('id', 'name')->get();

        if (!empty($countrys)) {
            return $this->success($countrys, __('Country list get successfully.'));
        } else {
            return $this->error(['message' => __('Country not found.')], __('Country not found.'));
        }
    }

    public function state_list(Request $request, $slug = '')
    {
        $store = getStore($slug);
        

        $rules = [
            'country_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        $state = State::select('name', 'id', 'country_id')->where('country_id', $request->country_id)->get();

        if (!empty($state) && $request->country_id != 0) {
            return $this->success($state, __('State list get successfully.'));
        } else {
            return $this->error(['message' => __('State not found.')], __('State not found.'));
        }
    }

    public function city_list(Request $request, $slug = '')
    {
        $store = getStore($slug);
        

        $rules = [
            'state_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        $City = City::select('name', 'id', 'state_id', 'country_id')->where('state_id', $request->state_id)->get();

        if (!empty($City)) {
            return $this->success($City, __('City list get successfully.'));
        } else {
            return $this->error(['message' => __('City not found.')], __('City not found.'));
        }
    }

    public function profile_update(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        // THE FIFTH INSTANCE OF ONE PATTERN, and it writes the E-MAIL -- which makes it a takeover
        // primitive rather than a privacy defect: own the address and every recovery flow follows.
        //
        // The body was:
        //     if (!auth('customers')->user()) { $customer_id = $request->customer_id; }
        //     else                            { $customer_id = auth('customers')->user()->id; }
        //     $user = Customer::find($customer_id);        // no store predicate
        //     if (!empty($request->email)) { $user->email = $request->email; }
        //
        // `custom.auth` logs a token's owner into whichever guard matches its class, so a
        // MERCHANT's or a DRIVER's token passed the middleware with auth('customers') null and took
        // the request-supplied branch. The siblings closed the same way are
        // Api\ApiController::change_password, Api\DeliveryBoyApiController::resetPassword,
        // AccountProfileController::store and Api\AuthController::logout.
        //
        // The $rules juggling was inert: 'customer_id' => 'required' only existed on the branch
        // that had already read the value.
        $customer = auth('customers')->user();

        if (! $customer instanceof Customer) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }

        // The slug must be the customer's own store. A customer row with no store passes, since
        // `customers.store_id` is nullable and imports write that table; this endpoint is
        // self-scoped by the check above, so which slug was used cannot change whose row it edits.
        if ($customer->store_id !== null && (int) $customer->store_id !== (int) $store->id) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }

        // Store-scoped uniqueness, as registration and the web account form both enforce: without
        // it a shopper can take over another shopper's address in their store and break both
        // accounts' login and recovery.
        if (! empty($request->email)) {
            $taken = Customer::where('email', $request->email)
                ->where('store_id', $customer->store_id)
                ->where('id', '!=', $customer->id)
                ->exists();

            if ($taken) {
                return $this->error(
                    ['message' => __('This Email already exist, please login.')],
                    __('This Email already exist, please login.')
                );
            }
        }

        // `customer_id` from the request is deliberately ignored.
        $user = $customer;

        if (!empty($user)) {
            if (!empty($request->first_name)) {
                $user->first_name = $request->first_name;
            }
            if (!empty($request->last_name)) {
                $user->last_name = $request->last_name;
            }
            if (!empty($request->email)) {
                $user->email = $request->email;
            }
            if (!empty($request->telephone)) {
                $user->mobile = $request->telephone;
            }
            $user->save();

            $user_detail_array['customer_id']   = $request->customer_id;
            $request->request->add($user_detail_array);
            $user_detail_response = $this->user_detail($request, $slug);
            $user_detail = (array)$user_detail_response->getData()->data;
            return $this->success(['message' => 'User updated successfully.', 'data' => $user_detail], __('User updated successfully.'));
        } else {
            return $this->error(['message' => __('User not found.')], __('User not found.'));
        }
    }

    public function user_detail(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $rules = [
            'customer_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $customer = Customer::find($request->customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $user_data = Customer::find($request->customer_id);
        $DeliveryAddress = DeliveryAddress::where('customer_id', $request->customer_id)->where('default_address', 1)->first();
        if (!empty($user_data)) {
            $user_array['id'] = $user_data->id;
            $user_array['first_name'] = $user_data->first_name;
            $user_array['last_name'] = $user_data->last_name;
            $user_array['image'] = !empty($user_data->profile_image) ? ($user_data->profile_image) : Storage::url('uploads/avtar.png');
            $user_array['name'] = $user_data->name;
            $user_array['email'] = $user_data->email;
            $user_array['mobile'] = $user_data->mobile;
            $user_array['defaulte_address_id'] = !empty($DeliveryAddress->id) ? $DeliveryAddress->id : 0;
            $user_array['country_id'] = !empty($DeliveryAddress->CountryData->name) ? $DeliveryAddress->CountryData->name : '';
            $user_array['state_id'] = !empty($DeliveryAddress->StateData->name) ? $DeliveryAddress->StateData->name : '';
            $user_array['city'] = !empty($DeliveryAddress->city) ? $DeliveryAddress->city : '';
            $user_array['address'] = !empty($DeliveryAddress->address) ? $DeliveryAddress->address : '';
            $user_array['postcode'] = !empty($DeliveryAddress->postcode) ? $DeliveryAddress->postcode : '';

            Log::channel('API_log')->info(json_encode($request->all()));
            return $this->success($user_array, __('User address updated successfully.'));
        } else {
            Log::channel('API_log')->info('User not found.');
            return $this->error(['message' => __('User not found.')], __('User not found.'));
        }
    }

    public function shipping(Request $request)
    {
        $slug = !empty($request->slug) ? $request->slug : '';
        $store = getStore($slug);
        if (!$store) {
            return $this->error(['message' => __('Store not found.')], __('Store not found.'));
        }

        $data = Shipping::where('store_id', $store->id)->get()->toArray();
        if (!empty($data)) {
            return $this->success($data, __('Shipping get successfully.'));
        } else {
            return $this->error(['message' => __('Shipping not found.')], __('Shipping not found.'));
        }
    }

    public function cart_check(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $rules = [
            'customer_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $customer = Customer::find($request->customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        // cart list api call
        if (!empty($request->customer_id)) {
            $cartlist_response = $this->cart_list($request, $slug);
            $cartlist = $cartlist_response->getData()->data;

            if (!empty($cartlist->product_list)) {
                foreach ($cartlist->product_list as $key => $product) {
                    if ($product->variant_id > 0) {
                        $productStock_data = ProductVariant::where('product_id', $product->product_id)->where('id', $product->variant_id)->first();
                        $product_data = Product::find($product->product_id);
                        if ($productStock_data->stock  < $product->qty) {
                            return $this->error(['message' => $product_data->name . ' insufficient stock.']);
                        }
                    } else {
                        $product_data = Product::find($product->product_id);
                        if ($product_data->product_stock < $product->qty) {
                            return $this->error(['message' => $product_data->name . ' insufficient stock.']);
                        }
                    }
                }
                return $this->success(['message' => __('Cart is ready.')]);
            } else {
                return $this->error(['message' => __('Cart is empty.')]);
            }
        }

        return $this->error([
            'message' => __("Cart is empty.")
        ], __("Cart is empty."));
    }

    public function cart_check_guest(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $cart = $request->all();

        $return_product_responce = [];
        if (!empty($cart)) {
            foreach ($cart as $key => $value) {
                if (!is_array($value)) {
                    continue;
                }
                $product = Product::find($value['product_id']);
                $qty = !empty($value['qty']) ? $value['qty'] : 0;
                $stock = 0;
                if ($value['varient_id'] != 0) {
                    $product = ProductVariant::where('product_id', $value['product_id'])->where('id', $value['varient_id'])->first();
                    $stock = !empty($product) ? $product->stock : 0;
                } else {
                    $stock = !empty($product) ? $product->product_stock : 0;
                }

                $return_product_responce[$key] = $value;
                if (!empty($product)) {
                    $status = true;
                    $message = __('Product have stock.');

                    if ($stock == 0) {
                        $status = false;
                        $message = __('Product have out of stock.');
                    } elseif ($stock < $qty) {
                        $status = false;
                        $message = 'Product have ' . $stock . ' in stock.';
                    }
                } else {
                    $status = false;
                    $message = __('Product no found.');
                }
                $return_product_responce[$key]['status'] = $status;
                $return_product_responce[$key]['message'] = $message;
                $return_product_responce[$key]['product_qty'] = $stock;
            }
            return $this->success(['cart' => $return_product_responce], __('Cart list get successfully.'));
        } else {
            return $this->error(['message' => __('Cart is empty.')], __('Cart is empty.'));
        }
    }

    public function bestseller(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $category_id = $request->category_id;

        $bestseller_query = Product::where('store_id', $store->id)->where('product_type', null);
        if (!empty($category_id)) {
            $bestseller_query->where('category_id', $category_id);
        }
        $bestseller_array = $bestseller_query->paginate(6);

        if (auth('customers')->user()) {
            $cart = Cart::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->count();
        } else {
            $cart = 0;
        }

        if (!empty($bestseller_array)) {
            return $this->success($bestseller_array, __("Products list get successfully."), 200, $cart);
        } else {
            return $this->error(['message' => __('Product not found.')], 'fail', 200, 0, $cart);
        }
    }

    public function bestseller_guest(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $category_id = $request->category_id;
        $cart = 0;

        $bestseller_array_query = Product::where('store_id', $store->id)->where('product_type', null);
        if (!empty($category_id)) {
            $bestseller_array_query->where('category_id', $category_id);
        }
        $bestseller_array = $bestseller_array_query->paginate(6);
        if (!empty($bestseller_array)) {
            return $this->success($bestseller_array, __("Products list get successfully."), 200, $cart);
        } else {
            return $this->error(['message' => __('Product no found.')], __('Product no found.'), 200, 0, $cart);
        }
    }

    public function tranding_category(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $Category = Category::where('trending', 1)->where('store_id', $store->id)->limit(4)->get();
        if (!empty($Category)) {
            return $this->success($Category, __('Trending Product list get successfully.'));
        } else {
            return $this->error(['message' => __('Trending Product not found.')], __('Trending Product not found.'));
        }
    }

    public function tranding_category_product(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $Product_query = Product::where('trending', 1)->where('product_type', null);
        if (!empty($request->main_category_id)) {
            $Product_query->where('category_id', $request->main_category_id);
        }
        $Product = $Product_query->where('store_id', $store->id)->paginate(10);

        if (!empty($Product)) {
            return $this->success($Product, __('Trending Product list get successfully.'));
        } else {
            return $this->error(['message' => __('Trending Product not found.')], __('Trending Product not found.'));
        }
    }

    public function tranding_category_product_guest(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $Product_query = Product::where('trending', 1)->where('product_type', null);
        if (!empty($request->main_category_id)) {
            $Product_query->where('category_id', $request->main_category_id);
        }
        $Product = $Product_query->where('store_id', $store->id)->paginate(10);

        if (!empty($Product)) {
            return $this->success($Product, __('Trending Product list get successfully.'));
        } else {
            return $this->error(['message' => __('Trending Product not found.')], __('Trending Product not found.'));
        }
    }

    public function home_category(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __('Store not found.'), 200, 0, 0);
        }
        

        $MainCategorys = Category::with('children:id,name,image_path,icon_path,parent_id')
            ->select('*', 'id as category_id', 'image_path as image', \DB::raw("REGEXP_REPLACE(name, '[^[:print:]]', '') AS name"))
            
            ->where('store_id', $store->id)
            ->paginate(6);

        if (auth('customers')->user()) {
            $cart = Cart::where('customer_id', auth('customers')->user()->id)
                
                ->where('store_id', $store->id)
                ->count();
        } else {
            $cart = 0;
        }

        if (!empty($MainCategorys)) {
            return $this->success($MainCategorys, __('Category list get successfully.'), 200, $cart);
        } else {
            return $this->error(['message' => __('Category not found.')], __('Category not found.'), 200, 0, $cart);
        }
    }

    public function sub_category(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $data['product'] = [];
        $data['subcategory'] = [];

        $maincategory = Category::find($request->category_id);

        if ($maincategory) {
            $SubCategory_query = Category::query()->where('store_id', $store->id);
            if (!empty($request->category_id)) {
                $SubCategory_query->where('parent_id', $maincategory->category_id);
            }
            $SubCategory = $SubCategory_query->get();
            $prepend_array_trend = [
                "id" => 'trending',
                "name" => "Trending Products",
                "image_url" => "",
                "image_path" => "",
                "icon_path" => "storage/upload/trending.png",
                "status" => 1,
                "category_id" => $request->category_id,
                "created_at" => "",
                "updated_at" => "",
                "icon_img_path" => "storage/upload/trending.png",
            ];

            $prepend_array = [
                "id" => 0,
                "name" => "All Products",
                "image_url" => "",
                "image_path" => "",
                "icon_path" => "storage/upload/all_product.png",
                "status" => 1,
                "category_id" => $request->category_id,
                "created_at" => "",
                "updated_at" => "",
                "icon_img_path" => "storage/upload/all_product.png"
            ];

            $SubCategory->prepend($prepend_array);
            if (!empty($maincategory) && $maincategory->trending == 1) {
                $SubCategory->prepend($prepend_array_trend);
            }

            $data['subcategory'] = $SubCategory;
        } else {
            $Product = Product::where('category_id', $request->category_id)->where('product_type', null)->paginate(10);
            $data['product'] = $Product;
        }
        if (!empty($data)) {
            return $this->success($data, __('Subcategory list get successfully.'));
        } else {
            return $this->error(['message' => __('Subcategory not found.')], __('Subcategory not found.'));
        }
    }

    public function sub_category_guest(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $data['product'] = [];
        $data['subcategory'] = [];

        $maincategory = Category::find($request->category_id);

        if ($maincategory) {
            $SubCategory_query = Category::query()->where('store_id', $store->id);
            if (!empty($request->category_id)) {
                $SubCategory_query->where('parent_id', $request->category_id);
            }

            $SubCategory = $SubCategory_query->get();
            $prepend_array_trend = [
                "id" => 'trending',
                "name" => "Trending Products",
                "image_url" => "",
                "image_path" => "",
                "icon_path" => "",
                "status" => 1,
                "category_id" => $request->category_id,
                "created_at" => "",
                "updated_at" => "",
                "icon_img_path" => ""
            ];

            $prepend_array = [
                "id" => 0,
                "name" => "All Products",
                "image_url" => "",
                "image_path" => "",
                "icon_path" => "",
                "status" => 1,
                "category_id" => $request->category_id,
                "created_at" => "",
                "updated_at" => "",
                "icon_img_path" => ""
            ];

            $SubCategory->prepend($prepend_array);
            if (!empty($maincategory) && $maincategory->trending == 1) {
                $SubCategory->prepend($prepend_array_trend);
            }
            $data['subcategory'] = $SubCategory;
        } else {
            $Product = Product::where('category_id', $request->category_id)->where('product_type', null)->paginate(10);
            $data['product'] = $Product;
        }
        if (!empty($data)) {
            return $this->success($data, __('Subcategory list get successfully.'));
        } else {
            return $this->error(['message' => __('Subcategory not found.')], __('Subcategory not found.'));
        }
    }

    public function check_variant_stock(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $rules = [
            'product_id' => 'required',
            'variant_sku' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $ProductVariant_array = [];
        $ProductVariant = ProductVariant::where('product_id', $request->product_id)->where('sku', $request->variant_sku)->where('store_id', $store->id)->first();
        if (!empty($ProductVariant)) {
            $ProductVariant_array['id'] = $ProductVariant->id;
            $ProductVariant_array['variant'] = $ProductVariant->variant;
            $ProductVariant_array['stock'] = $ProductVariant->stock;
            $ProductVariant_array['original_price'] = $ProductVariant->original_price;
            $ProductVariant_array['discount_price'] = $ProductVariant->discount_price;
            $ProductVariant_array['final_price'] = $ProductVariant->final_price;
        }

        if (!empty($ProductVariant_array)) {
            return $this->success($ProductVariant_array, __('Product variant get successfully.'));
        } else {
            return $this->error(['message' => __('Product variant not found.')]);
        }
    }

    /**
     * A SIGNED-IN SHOPPER CHANGES THEIR OWN PASSWORD. Nothing else.
     *
     * THE DEFECT THIS REPLACES was found by an adversarial review of the password-recovery fix,
     * which asked the question this endpoint answers badly: can an administrator's or a delivery
     * driver's token authorise a customer password change? Here it could, for any customer, in
     * any store. The body was:
     *
     *     if (!auth('customers')->user()) {
     *         $rules = ['customer_id' => 'required'];
     *         $customer_id = $request->customer_id;      // attacker-supplied
     *     } else {
     *         $customer_id = auth('customers')->user()->id;
     *     }
     *     ...
     *     $user = Customer::find($customer_id);          // no store predicate, no ownership check
     *     $user->password = bcrypt($request->password);
     *
     * The route carries `custom.auth`, and App\Http\Middleware\CustomAuth logs a token's owner
     * into whichever guard matches its tokenable class: a Customer into `customers`, a User into
     * `web`, a DeliveryBoy into `deliveryboy`. So a MERCHANT's or a DRIVER's token passes the
     * middleware and leaves auth('customers') null -- taking the first branch, where
     * `customer_id` was read straight off the request and `Customer::find()` applied no scope.
     *
     * Measured, in tests/Feature/Security/CustomerPasswordResetAdversarialTest:
     *   - a merchant's token set an arbitrary customer's password;
     *   - a DELIVERY DRIVER's token set the password of a customer belonging to a DIFFERENT
     *     STORE -- a cross-tenant account takeover from the least privileged principal the
     *     platform issues tokens to. Nothing resolves a tenant for the `deliveryboy` guard, so
     *     config/tenancy.php's `null_tenant => 'open'` left Customer unscoped for them.
     *
     * The sibling case -- a merchant reaching into another store -- happened to be refused, but
     * only because a merchant token DOES resolve a tenant and the global scope then narrowed the
     * lookup. A control that works by accident for one principal and not another is not a
     * control, so the check below is explicit and does not depend on the scope at all.
     *
     * $rules also had three assignment sites, the last unconditional, so the
     * 'customer_id' => 'required' rule was overwritten before \Validator ever saw it -- the id
     * was never even validated.
     *
     * COMPATIBILITY. The `customer_id` branch was reachable ONLY by a non-customer token, so no
     * customer-facing client could ever have used it: a customer's token populates
     * auth('customers') and takes the other branch. Neither Flutter client in this project calls
     * this endpoint (it is absent from tests/Support/ApiContract/manifests/flutter-endpoints.json
     * and nothing in the admin app references it), and the self-service journey -- a Bearer token
     * plus `password`, no `customer_id` -- is unchanged, including its request shape, its success
     * message and its 401 envelope.
     */
    public function change_password(Request $request, $slug = '')
    {
        $customer = auth('customers')->user();

        // AUTHENTICATION IS NOW THE PRINCIPAL, not a hint. A merchant, a platform operator or a
        // delivery driver holds a valid token and passes `custom.auth`, but is not a shopper and
        // has no business on this endpoint. Resetting a customer's password administratively is a
        // separate, session-authenticated surface and was never this one's job.
        if (! $customer instanceof Customer) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }

        $rules = [
            'password' => 'required|string|min:8'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        // THE STORE IN THE PATH MUST BE THE CUSTOMER'S OWN. The slug was resolved here and then
        // never read, so the endpoint was indifferent to which storefront it was called through.
        // Checked explicitly rather than left to the global tenant scope, which is open whenever
        // no tenant resolves.
        //
        // A customer whose row carries NO store passes. `customers.store_id` is nullable and
        // imports write that table (config/tenancy.php names ShopifyCustomerController among the
        // writers), so such rows are plausible -- and refusing them would deny a real shopper
        // their own password change to close nothing: this endpoint is self-scoped by the check
        // above, so where it acts does not depend on which slug was used.
        $store = getStore($slug);

        if (empty($store)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }

        if ($customer->store_id !== null && (int) $customer->store_id !== (int) $store->id) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }

        // `customer_id` from the request is deliberately ignored. The only account this endpoint
        // can change is the one the presented token belongs to.
        $customer->password = bcrypt($request->password);
        $customer->save();

        return $this->success(['message' => __('Password updated.')], __('Password updated.'));
    }

    public function change_address(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $rules = [
            'customer_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $user = DeliveryAddress::where('customer_id', $request->customer_id)->where('title', 'main')->first();
        if (empty($user)) {
            $user = new DeliveryAddress();
        }
        if (!empty($user)) {
            $default_address = !empty($request->default_address) ? 1 : 0;

            $user->country_id = $request->country;
            $user->state_id = $request->state;
            $user->city_id = $request->city;
            $user->customer_id = $request->customer_id;
            $user->title = 'main';
            $user->address = $request->address;
            $user->postcode = $request->postcode;
            $user->default_address = $default_address;
            $user->store_id = $store->id;
            $user->save();

            if ($default_address == 1) {
                $u_a_a['default_address'] = 0;
                DeliveryAddress::where('customer_id', $request->customer_id)->where('id', '!=', $user->id)->update($u_a_a);
            }

            $user_detail_array['customer_id']   = $request->customer_id;
            $request->request->add($user_detail_array);
            $user_detail_response = $this->user_detail($request);
            $user_detail = (array)$user_detail_response->getData()->data;
            return $this->success(['message' => __('Address updated successfully.'), 'data' => $user_detail], __('Address updated successfully.'));
        } else {
            return $this->error(['message' => __('User not found.')], __('User not found.'));
        }
    }

    public function delete_address(Request $request, $slug = '')
    {
        $rules = [
            'address_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        $DeliveryAddress = DeliveryAddress::where('id', $request->address_id)->first();
        if (!empty($DeliveryAddress)) {
            $DeliveryAddress->delete();
            return $this->success(['message' => __('Address deleted successfully.')], __('Address deleted successfully.'));
        } else {
            return $this->error(['message' => __('Address not found.')], __('Address not found.'));
        }
    }

    public function update_user_image(Request $request, $slug = '')
    {
        $store = getStore($slug);
        

        $rules = [
            'customer_id' => 'required',
            'image' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        $customer = Customer::find($request->customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $theme_image = $request->image;
        
        $user = Customer::find($request->customer_id);
        if (!empty($user)) {
            $store = Store::findOrFail($user->store_id);
            $cover_image = upload_theme_image( $theme_image, 0, $store->created_by);
            if (File::exists(base_path($user->profile_image))) {
                File::delete(base_path($user->profile_image));
            }
            $user->profile_image = $cover_image['image_path'];
            $user->save();
        }

        if (!empty($user)) {
            return $this->success(['message' => $cover_image['image_path']], $cover_image['image_path']);
        } else {
            return $this->error(['message' => __('User not found.')], __('User not found.'));
        }
    }

    public function confirm_order(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        if (auth('customers')->user()) {
            $rules = [
                'payment_type' => 'required',
                'delivery_id' => 'required',
            ];
            $customer_id         = auth('customers')->user()->id;
        } else {
            $rules = [
                'customer_id' => 'required',
                'payment_type' => 'required',
                'delivery_id' => 'required',
            ];
            $customer_id         = $request->customer_id ?? null;
        }

        $cartlist_final_price = 0;
        $final_price = 0;
        $customer = Customer::find($customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        // cart list api call
        if (!empty($customer_id)) {
            $cart_list['customer_id']   = $customer_id;
            $cart_list['slug']   = $slug;
            $request->request->add($cart_list);
            $cartlist_response = $this->cart_list($request, $slug);
            $cartlist = (array)$cartlist_response->getData()->data;


            if (empty($cartlist['product_list'])) {
                return $this->error(['message' => 'Cart is empty.']);
            }

            $final_price = $cartlist['final_price'] - $cartlist['tax_price'];
            $tax_id = $cartlist['tax_id'];

            $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
            $billing = $request->billing_info;

            $products = $cartlist['product_list'];
        } else {
            return $this->error(['message' => __('User not found.')],  __('User not found.'));
        }
        if (empty($billing['firstname'])) {
            return $this->error(['message' => __('Billing first name not found.')], __('Billing first name not found.'));
        }
        if (empty($billing['lastname'])) {
            return $this->error(['message' => __('Billing last name not found.')], __('Billing last name not found.'));
        }
        if (empty($billing['email'])) {
            return $this->error(['message' => __('Billing email not found.')], __('Billing email not found.'));
        }
        if (empty($billing['billing_user_telephone'])) {
            return $this->error(['message' => __('Billing telephone not found.')], __('Billing telephone not found.'));
        }
        if (empty($billing['billing_address'])) {
            return $this->error(['message' => __('Billing address not found.')], __('Billing address not found.'));
        }
        if (empty($billing['billing_postecode'])) {
            return $this->error(['message' => __('Billing postecode not found.')], __('Billing postecode not found.'));
        }
        if (empty($billing['billing_country'])) {
            return $this->error(['message' => __('Billing country not found.')], __('Billing country not found.'));
        }
        if (empty($billing['billing_state'])) {
            return $this->error(['message' => __('Billing state not found.')], __('Billing state not found.'));
        }
        if (empty($billing['billing_city'])) {
            return $this->error(['message' => __('Billing city not found.')], __('Billing city not found.'));
        }
        if (empty($billing['delivery_address'])) {
            return $this->error(['message' => __('Delivery address not found.')], __('Delivery address not found.'));
        }
        if (empty($billing['delivery_postcode'])) {
            return $this->error(['message' => __('Delivery postcode not found.')], __('Delivery postcode not found.'));
        }
        if (empty($billing['delivery_country'])) {
            return $this->error(['message' => __('Delivery country not found.')], __('Delivery country not found.'));
        }
        if (empty($billing['delivery_state'])) {
            return $this->error(['message' => __('Delivery state not found.')], __('Delivery state not found.'));
        }
        if (empty($billing['delivery_city'])) {
            return $this->error(['message' => __('Delivery city not found.')], __('Delivery city not found.'));
        }

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        // coupon api call
        $order_array['coupon_info'] = null;
        if (!empty($request->coupon_info)) {
            $coupon_data = $request->coupon_info;
            $apply_coupon = [
                'coupon_code' => $coupon_data['coupon_code'],
                'sub_total' => $cartlist_final_price
            ];
            $request->request->add($apply_coupon);
            $apply_coupon_response = $this->apply_coupon($request, $slug);
            $apply_coupon = (array)$apply_coupon_response->getData()->data;

            $order_array['coupon_info']['message'] = $apply_coupon['message'];
            $order_array['coupon_info']['status'] = false;
            if (!empty($apply_coupon['final_price'])) {
                $cartlist_final_price = $apply_coupon['final_price'];
                $order_array['coupon_info']['status'] = true;
            }
        }

        $delivery_price = 0;
        $tax_price = $cartlist['tax_price'];
        $user = User::where('id', $store->created_by)->first();
        if ($user->type == 'admin') {
            $plan = getStore($slug);
        }
        if ($plan->shipping_method == 'on') {
            if (!empty($request->method_id)) {
                $del_charge = new CartController();
                $delivery_charge = $del_charge->get_shipping_method($request, $slug);
                $content = $delivery_charge->getContent();

                $data = json_decode($content, true);

                $delivery_price = $data['total_final_price'];

                $tax_price = $data['final_tax_price'];
            }
        } else {
            if (!empty($tax_price)) {
                $tax_price = $tax_price;
            } else {
                $tax_price = 0;
            }
        }
        // Order stock decrease start
        if (!empty($products)) {
            foreach ($products as $key => $product) {
                $product_id = $product->product_id;
                $variant_id = $product->variant_id;
                $qtyy = !empty($product->qty) ? $product->qty : 0;

                if (!empty($product_id) && !empty($variant_id) && $product_id != 0 && $variant_id != 0) {

                    $ProductStock = ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first();
                    if (!empty($ProductStock)) {
                    } else {
                        return $this->error(['message' => __('Product not found.')], __('Product not found.'));
                    }
                } elseif (!empty($product_id) && $product_id != 0) {

                    $Product = Product::where('id', $product_id)->first();
                    if (!empty($Product)) {
                    } else {
                        return $this->error(['message' => __('Product not found.')], __('Product not found.'));
                    }
                } else {
                    return $this->error(['message' => __('Please fill proper product json field.')], __('Please fill proper product json field.'));
                }
            }
        }
        // Order stock decrease end

        // add in Order Coupon Detail table start
        if (!empty($request->coupon_info)) {
            $coupon_data = $request->coupon_info;

            $discount_string = '-' . $coupon_data['coupon_discount_number'];
            $CURRENCY = Utility::GetValueByName('CURRENCY');
            $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME');
            if ($coupon_data['coupon_discount_type'] == 'flat') {
                $discount_string .= $CURRENCY;
            } else {
                $discount_string .= '%';
            }
            $discount_string .= ' ' . __('for all products');
            $discount = '-' . $coupon_data['coupon_discount_amount'];
            $discount_string2 = '(' . $discount . ' ' . $CURRENCY_NAME . ')';

            $order_array['coupon_info']['code'] = $coupon_data['coupon_code'];
            $order_array['coupon_info']['discount'] = $discount;
            $order_array['coupon_info']['discount_string'] = $discount_string;
            $order_array['coupon_info']['discount_string2'] = $discount_string2;
            $order_array['coupon_info']['price'] = SetNumber($coupon_data['coupon_final_amount']);
            $order_array['coupon_info']['discount_amount'] = SetNumber($coupon_data['coupon_final_amount']);

            $order_array['coupon']['code'] = $coupon_data['coupon_code'];
            $order_array['coupon']['discount_string'] = $discount_string;
            $order_array['coupon']['price'] = SetNumber($coupon_data['coupon_final_amount']);
        }
        // add in Order Coupon Detail table end

        // add in Order Tax Detail table start
        $tax = Tax::find($tax_id);
        $tax_rate = 0;
        if (isset($tax)) {
            if ($tax->tax_methods()) {
                foreach (json_decode($tax->tax_methods()) as $method) {
                    $tax_rate += $method->tax_rate;
                }
            }
        }
        $order_array['tax']['tax_id'] = $tax_id ?? 0;
        $order_array['tax']['tax_name'] = $tax->name ?? 'Tax';
        $order_array['tax']['tax_price'] = $tax_price ?? 0;
        $order_array['tax']['tax_rate'] = $tax_rate ?? 0;
        // add in Order Tax Detail table end
        $order_array['product'] = $products;
        $order_array['billing_information']['name'] = $billing['firstname'] . ' ' . $billing['firstname'];
        $order_array['billing_information']['address'] = $billing['billing_address'];
        $order_array['billing_information']['email'] = $billing['email'];
        $order_array['billing_information']['phone'] = $billing['billing_user_telephone'];
        $order_array['billing_information']['country'] = $billing['billing_country'];
        $order_array['billing_information']['state'] = $billing['billing_state'];
        $order_array['billing_information']['city'] = $billing['billing_city'];
        $order_array['billing_information']['postecode'] = $billing['billing_postecode'];
        $order_array['delivery_information']['name'] = $billing['firstname'] . ' ' . $billing['firstname'];
        $order_array['delivery_information']['address'] = $billing['delivery_address'];
        $order_array['delivery_information']['email'] = $billing['email'];
        $order_array['delivery_information']['phone'] = $billing['billing_user_telephone'];
        $order_array['delivery_information']['country'] = $billing['delivery_country'];
        $order_array['delivery_information']['state'] = $billing['delivery_state'];
        $order_array['delivery_information']['city'] = $billing['delivery_city'];
        $order_array['delivery_information']['postecode'] = $billing['delivery_postcode'];

        $payment_data = Utility::payment_data($request->payment_type);
        $order_array['paymnet'] = empty('storage/' . $payment_data['image']) ? 'storage/' . $payment_data['image'] : Storage::url('uploads/payment/cod.png');

        $Shipping = Shipping::find($request->delivery_id);
        $delivery_image = '';
        if (!empty($Shipping)) {
            $delivery_image = $Shipping->image_path;
        } else {
            $delivery_image = Storage::url('uploads/delivery.png');
        }
        $order_array['delivery'] = $delivery_image;
        $order_array['delivery_charge'] = SetNumber($delivery_price);
        $order_array['subtotal'] = SetNumber($final_price);
        $order_array['final_price'] = SetNumber($cartlist_final_price);
        return $this->success($order_array, __('Order has been confirmed successfully.'));
    }

    public function order_list(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        if (!auth('customers')->user()) {
            $rules = [
                'customer_id' => 'required'
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ], $messages->first());
            }
            $customer_id = $request->customer_id;
        } else {
            $customer_id = auth('customers')->user()->id;
        }

        $customer = Customer::find($customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $orders = Order::select('id', 'order_date', 'delivery_date', 'product_order_id as product_order_id', 'order_date as date', \DB::raw('CAST(final_price AS DOUBLE) as amount'), 'delivery_id as delivery_id', 'delivered_status', 'return_status', 'reward_points as reward_points')
            ->where('customer_id', $customer_id)
            
            ->where('store_id', $store->id)
            ->paginate(10);
        if (!empty($orders)) {
            return $this->success($orders, __('Orders get successfully.'));
        } else {
            return $this->error(['message' => __('Order not found.')], __('Order not found.'));
        }
    }

    public function return_order_list(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        if (!auth('customers')->user()) {
            $rules = [
                'customer_id' => 'required'
            ];

            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return $this->error([
                    'message' => $messages->first()
                ], $messages->first());
            }
            $customer_id = $request->customer_id;
        } else {
            $customer_id = auth('customers')->user()->id;
        }
        $customer = Customer::find($customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $orders = Order::select('id', 'order_date', 'delivery_date', 'product_order_id as product_order_id', 'order_date as date', 'final_price as amount', 'delivery_id as delivery_id', 'delivered_status as delivered_status', 'reward_points as reward_points')
            ->where('customer_id', $customer_id)
            
            ->where('store_id', $store->id)
            ->whereRaw('( delivered_status = 3 OR return_price > 0 )')
            ->paginate(10);
        if (!empty($orders)) {
            return $this->success($orders, __('Order return successfully.'));
        } else {
            return $this->error(['message' => __('Order not found.')], __('Order not found.'));
        }
    }

    public function order_detail(Request $request, $slug = '')
    {
        $store = getStore($slug);
        

        $rules = [
            'order_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        // S12(a). THIS ENDPOINT RESOLVED $store FROM THE SLUG AND THEN NEVER USED IT. It called
        // Order::order_detail($request->order_id) -- `Order::find()` with the store id read off
        // the order itself -- with no customer predicate and no store predicate, so ANY holder of
        // ANY valid token read any order on the platform. Reproduced before this change: a
        // customer token belonging to store B returned store A's full order document, HTTP 200.
        // `Order` carries neither the tenancy trait nor an entry in config/tenancy.php's
        // scoped_models allowlist, so no global scope supplied the missing predicate either.
        if (!$this->resolveViewableOrder($request->order_id, $store)) {
            // The endpoint's OWN not-found envelope, verbatim, for two reasons: the Flutter app
            // already handles this path, and "not yours" must be indistinguishable from "no such
            // order" so the endpoint cannot enumerate order ids. Never 401 -- repo B's
            // lib/network/network.dart forces a logout on any 401.
            return $this->error(__('Order not found.'), __('Order not found.'));
        }

        $order = Order::order_detail($request->order_id);
        if (!empty($order['message'])) {
            return $this->error($order['message'], $order['message']);
        } else {
            return $this->success($order, __('Order detail get successfully.'));
        }
    }

    /**
     * The order a storefront-API caller is entitled to read, or null.
     *
     * S12(a). Deliberately shaped like DashboardController::resolveChangeableOrder() -- the
     * admin API's equivalent helper -- so the two surfaces cannot drift into different ideas of
     * who owns an order. `custom.auth` (App\Http\Middleware\CustomAuth) logs a token's owner
     * into whichever of the three guards matches its tokenable class, so all three principals can
     * legitimately arrive here and each needs its own relationship to the order:
     *
     *   Customer      -> they placed it. orders.customer_id must equal their id and be non-zero;
     *                    a guest order carries customer_id = 0, so 0 can never match.
     *   DeliveryBoy   -> it is assigned to them. orders.deliveryboy_id, NOT orders.delivery_id --
     *                    the latter is a Shipping id (app/Models/Order.php:122-123) and both
     *                    columns exist, so confusing them would silently admit nobody or
     *                    everybody.
     *   User          -> the platform operator, or an actor whose own tenant owns the order's
     *                    store. `created_by = creatorId()` is the predicate used by
     *                    DashboardController::resolveOwnedStore() and StoreController, so staff
     *                    keep working rather than being locked out alongside attackers.
     *
     * Anything else, including an unauthenticated caller, returns null and the endpoint answers
     * its ordinary not-found. Fails closed on every branch.
     *
     * The slug's store is checked too when one resolved, as defence in depth: a customer of store
     * A asking store B's endpoint for their own order gets nothing, so the slug cannot be used to
     * pivot. It is NOT the primary control -- the ownership comparisons above are -- because
     * getStore() resolves from a URL segment the caller chooses.
     */
    private function resolveViewableOrder($orderId, $store = null)
    {
        if (empty($orderId)) {
            return null;
        }

        $order = Order::find($orderId);

        if (!$order) {
            return null;
        }

        if (!empty($store) && (int) $order->store_id !== (int) $store->id) {
            return null;
        }

        $customer = auth('customers')->user();

        if ($customer) {
            $orderCustomerId = (int) ($order->customer_id ?? 0);

            return ($orderCustomerId > 0 && $orderCustomerId === (int) $customer->id) ? $order : null;
        }

        $boy = auth('deliveryboy')->user();

        if ($boy) {
            return ((int) ($order->deliveryboy_id ?? 0) === (int) $boy->id && !empty($order->deliveryboy_id))
                ? $order
                : null;
        }

        $actor = auth()->user();

        if ($actor instanceof User) {
            if (strtolower(trim((string) $actor->type)) === 'super admin') {
                return $order;
            }

            $ownedStoreIds = Store::where('created_by', $actor->creatorId())->pluck('id')->all();

            return in_array((int) $order->store_id, array_map('intval', $ownedStoreIds), true) ? $order : null;
        }

        return null;
    }

    public function order_status_change(Request $request, $slug = '')
    {
        $store = getStore($slug);
        

        $data['order_id'] = $request->order_id;
        $data['order_status'] = $request->order_status;

        $date = Order::order_status_change($data);
        if ($date['status'] == 'success') {
            return $this->success(['message' => $date['message']], $date['message']);
        } else {
            return $this->error(['message' => $date['message']], $date['message']);
        }
    }

    public function product_return(Request $request, $slug = '')
    {
        $store = getStore($slug);
        
        $data['product_id'] = $request->product_id;
        $data['variant_id'] = $request->variant_id;
        $data['order_id']   = $request->order_id;
        
        $responce = Order::product_return($data);
        if ($responce['status'] == 'success') {
            return $this->success(['message' => $responce['message']], $responce['message']);
        } else {
            return $this->error(['message' => $responce['message']], $responce['message']);
        }
    }

    public function navigation(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $MainCategorys = Category::where('store_id', $store->id)->get();

        $navigation = [];
        if (!empty($MainCategorys)) {
            foreach ($MainCategorys as $key => $Category) {
                $navigation[$key]['image'] = $Category->image_path;
                $navigation[$key]['icon_img_path'] = $Category->icon_path;
                $navigation[$key]['name'] = $Category->name;
                $navigation[$key]['id'] = $Category->id;
                $navigation[$key]['sub_category'] = [];

            }
            return $this->success($navigation,  __('Category get successfully.'));
        } else {
            return $this->error(['message' => __('Category not found.')], __('Category not found.'));
        }
    }

    public function tax_guest(Request $request, $slug = '')
    {
        if ($slug == "") {
            $slug = $request->slug;
        }
        $store = getStore($slug);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $rules = [
            'sub_total' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        $data['slug'] = $request->slug;
        $data['store_id'] = $store->id;
        $data['sub_total'] = $request->sub_total;
        $cart_array  = Tax::TaxCount($data);

        return $this->success($cart_array, __('Tax data get successfully.'));
    }

    public function extra_url(Request $request, $slug = '')
    {
        $store = getStore($slug);

        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $url['terms'] = url('terms');
        $url['contact_us'] = url('contact-us');
        $url['return_policy'] = url('return-policy');
        $url['insta'] = 'https://www.instagram.com/rajodiyainfo/?hl=en';
        $url['youtube'] = 'https://www.youtube.com/';
        $url['messanger'] = 'https://workdo.io/';
        $url['twitter'] = 'https://twitter.com/rajodiya1';
        return $this->success($url, __('Social url get successfully.'));
    }

    public function loyality_program_json(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $loyality_program_json = Utility::loyality_program_json( $store->id);
        $loyality_program = [];
        foreach ($loyality_program_json as $key => $lp_value) {
            foreach ($lp_value['inner-list'] as $key => $value) {
                $loyality_program[$lp_value['section_slug']][$value['field_slug']] = $value['field_default_text'];
                if (!empty($value['value'])) {
                    $loyality_program[$lp_value['section_slug']][$value['field_slug']] = $value['value'];
                }
            }
        }
        $orderQuery = Order::query();
        if (auth('customers')->user()) {

            $Order = (clone $orderQuery)->select(
                \DB::raw('CAST(SUM(reward_points) AS DECIMAL(10,2))'),
                \DB::raw('FORMAT((product_price),1,2)')
            )->where('customer_id', auth('customers')->user()->id)->first();
            $loyality_program['point'] = $Order ? number_format($Order->reward_points, 2) : number_format(0, 2);

            $loyality_program['reward_history'] = (clone $orderQuery)
                ->select('*', \DB::raw('CAST(SUM(reward_points) AS DECIMAL(10,2)) AS total_reward_points'))
                ->selectRaw('FORMAT(product_price, 2) AS product_price')
                ->selectRaw('FORMAT(coupon_price, 2) AS coupon_price')
                ->selectRaw('FORMAT(delivery_price, 2) AS delivery_price')
                ->selectRaw('FORMAT(tax_price, 2) AS tax_price')
                ->selectRaw('CAST(FORMAT(final_price, 2) AS CHAR) AS final_price')
                ->selectRaw('FORMAT(return_price, 2) AS return_price')
                ->where('customer_id', auth('customers')->user()->id)
                ->where('store_id', $store->id)
                
                ->orderBy('id', 'desc')
                ->paginate(10);
        } else {
            $loyality_program['point'] = number_format(0, 2);
            $loyality_program['reward_history'] = (clone $orderQuery)->where('customer_id', 0)->where('store_id', $store->id)->orderBy('id', 'desc')->paginate(10);
        }

        return $this->success($loyality_program,  __('Loyality program get succssfully.'));
    }

    public function loyality_reward(Request $request, $slug = '')
    {
        $store = getStore($slug);
        

        $user_id = $request->user_id;
        $Order = Order::select('reward_points')->where('customer_id', $user_id)->sum('reward_points');
        return $this->success(['point' => number_format($Order, 2)], __('Reward points get succssfully.'));
    }

    public function payment_sheet(Request $request, $slug = '')
    {
        $store = getStore($slug);
        

        $pk_key = Utility::GetValueByName('publishable_key', $store->id);
        $sk_key = Utility::GetValueByName('stripe_secret', $store->id);

        if (empty($sk_key) && empty($sk_key)) {
            return $this->error(['message' => 'publishable key or stripe secret not found.']);
        }

        \Stripe\Stripe::setApiKey($sk_key);
        try {
            // Create a PaymentIntent with amount and currency
            $paymentIntent = \Stripe\PaymentIntent::create([
                'amount' => $request['price'],
                'currency' => $request['currency'],
                'automatic_payment_methods' => [
                    'enabled' => true,
                ],
            ]);
            $output = [
                'clientSecret' => $paymentIntent->client_secret,
            ];
            return json_encode($output);
        } catch (Error $e) {

            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function notify_user(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $rules = [
            'user_id' => 'required',
            'product_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }
        
        $Product = Product::find($request->product_id);
        if (empty($Product)) {
            return $this->error(['message' => __('Product not found.')], __('Product not found.'));
        }
        
        $user = User::find($request->user_id);
        if (empty($user)) {
            return $this->error(['message' => __('User not found.')], __('User not found.'));
        }
        
        $values['user_id'] = $request->user_id;
        $values['product_id'] = $request->product_id;
        $values['store_id'] = $store->id;
        $values['created_at'] = now();
        $values['updated_at'] = now();
        
        $NotifyUser = DB::table('notify_users')->where('user_id', $request->user_id)->where('product_id', $request->product_id)->where('store_id', $store->id)->first();
        if (empty($NotifyUser)) {
            $NotifyUser = DB::table('notify_users')->insert($values);
        }

        if (!empty($NotifyUser)) {
            return $this->success(['point' => __('User notify successfully.')], __('User notify successfully.'));
        } else {
            return $this->error(['message' => __('Somthing went wong.')], __('Somthing went wong.'));
        }
    }

    public function recent_product(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $Products = Product::where('store_id', $store->id)->where('product_type', null)->orderBy('id', 'DESC')->limit(10)->paginate(10);

        if (!empty($Products)) {
            return $this->success($Products, __('Products get successfully.'));
        } else {
            return $this->error(['message' => __('Products not found.')], __('Products not found.'));
        }
    }

    public function releted_product(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }

        $rules = [
            'product_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        $product_id = $request->product_id;
        $product = Product::find($product_id);
        if (!empty($product)) {
            $releted_product_query = Product::where('store_id', $store->id)->where('product_type', null)->where('id', '!=', $product_id);
            
            $releted_product_query->where('category_id', $product->category_id);
            $Products = $releted_product_query->orderBy('id', 'DESC')->paginate(10);
            if (!empty($Products)) {
                return $this->success($Products, __('Related products get successfully.'));
            } else {
                return $this->error(['message' => __('Related products not found.')], __('Related products not found.'));
            }
        } else {
            return $this->error(['message' => __('Related products not found.')], __('Related products not found.'));
        }
    }

    public function user_delete(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        

        $rules = [
            'user_id' => 'required'
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        $user = User::find($request->user_id);
        if (!empty($user)) {
            $delivery_address = DeliveryAddress::where('customer_id', $request->user_id);
            $orders = Order::where('customer_id', $request->user_id);
            $wishlist = Wishlist::where('customer_id', $request->user_id);
            $review = Testimonial::where('user_id', $request->user_id);

            $wishlist->delete();
            $orders->delete();
            $delivery_address->delete();
            $review->delete();
            $user->delete();

            return $this->success(['message' => __('User Deleted successfully.')], __('User Deleted successfully.'));
        } else {
            return $this->error(['message' => __('User not found.')], __('User not found.'));
        }
    }

    public function subscribe(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        if (!$store) {
            return $this->error(['message' => __('Something went wrong.')], __('Something went wrong.'));
        }
        $validator = \Validator::make(
            $request->all(),
            [
                'email' => ['required', 'unique:newsletters'],
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        $newsletter                 = new Newsletter();
        $newsletter->email         = $request->email;
        if (auth('customers')->user()) {
            $newsletter->customer_id         = auth('customers')->user()->id;
        } else {
            $newsletter->customer_id         = '0';
        }
        $newsletter->store_id       = $store->id;
        $newsletter->save();

        return $this->success(['message' => __('Subscribe added successfully.')], __('Subscribe added successfully.'));
    }

    public function discountProducts(Request $request, $slug = '')
    {

        $store = getStore($slug);
        if (!$store) {
            return $this->error(['message' => __('Something went wrong.')], __('Something went wrong.'));
        }

        if (auth('customers')->user()) {
            $cart = Cart::where('customer_id', auth('customers')->user()->id)->where('store_id', $store->id)->count();
        } else {
            $cart = 0;
        }

        $currentDate = Carbon::now()->toDateString();
        $product_ids = FlashSale::where('store_id', $store->id)
            ->where('is_active', 1)
            ->whereDate('start_date', '<=', $currentDate)
            ->whereDate('end_date', '>=', $currentDate)
            ->pluck('id')
            ->toArray();
        $products = Product::with('ProductData:id,name,image_path', 'CategoryctData:id,name')->where('store_id', $store->id)->whereIn('id', $product_ids)->paginate(10);

        if (!empty($products)) {
            return $this->success($products,  __('Products get successfully.'), 200, $cart);
        } else {
            return $this->error(['message' => __('Products not found.')],  __('Products not found.'), 200, $cart);
        }
    }

    public function add_review(Request $request, $slug = '')
    {
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $rules = [
            'category_id' => 'required',
            'product_id' => 'required',
            'rating_no' => 'required',
            'title' => 'required',
            'description' => 'required',
            'status' => 'required',
        ];

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        $Testimonial = new Testimonial();
        $Testimonial->category_id = $request->category_id;
        $Testimonial->product_id = $request->product_id;
        $Testimonial->rating_no = $request->rating_no;
        $Testimonial->title = $request->title;
        $Testimonial->description = $request->description;
        $Testimonial->status = $request->status;
        $Testimonial->store_id = $store->id;
        $Testimonial->save();

        return $this->success(['message' => __('Review added successfully.')], __('Review added successfully.'));
    }

    public function ordersave(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
            $storeId = $store->id;

$currentCurrencyCode = \App\Models\Utility::GetValueByName('CURRENCY', $storeId) ?: 'USD';
$currentCurrencySymbol = \App\Models\Utility::GetValueByName('currency_symbol', $storeId) ?: '$';
        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();

        if (auth('customers')->user()) {
            $rules = [
                'payment_type' => 'required',
                'delivery_id' => 'required',
            ];
            $customer_id         = auth('customers')->user()->id;
        } else {
            $rules = [
                'customer_id' => 'required',
                'payment_type' => 'required',
                'delivery_id' => 'required',
            ];
            $customer_id         = $request->customer_id ?? null;
        }
        $customer = Customer::find($customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $cartlist_final_price = 0;
        $final_price = 0;
        // cart list api call

        if (!empty($customer_id)) {
            $cart_list['customer_id']   = $customer_id;
            $cart_list['slug']   = $slug;
            $request->request->add($cart_list);
            $cartlist_response = $this->cart_list($request, $slug);
            $cartlist = (array)$cartlist_response->getData()->data;


            if (empty($cartlist['product_list'])) {
                return $this->error(['message' => 'Cart is empty.']);
            }

            $final_price = $cartlist['final_price'] - $cartlist['tax_price'];
            $tax_id = $cartlist['tax_id'];

            $cartlist_final_price = !empty($cartlist['final_price']) ? $cartlist['final_price'] : 0;
            $billing = $request->billing_info;

            $products = $cartlist['product_list'];
        } else {
            return $this->error(['message' => __('User not found.')],  __('User not found.'));
        }
        if (empty($billing['firstname'])) {
            return $this->error(['message' => __('Billing first name not found.')], __('Billing first name not found.'));
        }
        if (empty($billing['lastname'])) {
            return $this->error(['message' => __('Billing last name not found.')], __('Billing last name not found.'));
        }
        if (empty($billing['email'])) {
            return $this->error(['message' => __('Billing email not found.')], __('Billing email not found.'));
        }
        if (empty($billing['billing_user_telephone'])) {
            return $this->error(['message' => __('Billing telephone not found.')], __('Billing telephone not found.'));
        }
        if (empty($billing['billing_address'])) {
            return $this->error(['message' => __('Billing address not found.')], __('Billing address not found.'));
        }
        if (empty($billing['billing_postecode'])) {
            return $this->error(['message' => __('Billing postecode not found.')], __('Billing postecode not found.'));
        }
        if (empty($billing['billing_country'])) {
            return $this->error(['message' => __('Billing country not found.')], __('Billing country not found.'));
        }
        if (empty($billing['billing_state'])) {
            return $this->error(['message' => __('Billing state not found.')], __('Billing state not found.'));
        }
        if (empty($billing['billing_city'])) {
            return $this->error(['message' => __('Billing city not found.')], __('Billing city not found.'));
        }
        if (empty($billing['delivery_address'])) {
            return $this->error(['message' => __('Delivery address not found.')], __('Delivery address not found.'));
        }
        if (empty($billing['delivery_postcode'])) {
            return $this->error(['message' => __('Delivery postcode not found.')], __('Delivery postcode not found.'));
        }
        if (empty($billing['delivery_country'])) {
            return $this->error(['message' => __('Delivery country not found.')], __('Delivery country not found.'));
        }
        if (empty($billing['delivery_state'])) {
            return $this->error(['message' => __('Delivery state not found.')], __('Delivery state not found.'));
        }
        if (empty($billing['delivery_city'])) {
            return $this->error(['message' => __('Delivery city not found.')], __('Delivery city not found.'));
        }

        $validator = \Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return $this->error([
                'message' => $messages->first()
            ], $messages->first());
        }

        // coupon api call
        $order_array['coupon_info'] = null;
        if (!empty($request->coupon_info)) {
            $coupon_data = $request->coupon_info;
            $apply_coupon = [
                'coupon_code' => $coupon_data['coupon_code'],
                'sub_total' => $cartlist_final_price
            ];
            $request->request->add($apply_coupon);
            $apply_coupon_response = $this->apply_coupon($request, $slug);
            $apply_coupon = (array)$apply_coupon_response->getData()->data;

            $order_array['coupon_info']['message'] = $apply_coupon['message'];
            $order_array['coupon_info']['status'] = false;
            if (!empty($apply_coupon['final_price'])) {
                $cartlist_final_price = $apply_coupon['final_price'];
                $order_array['coupon_info']['status'] = true;
            }
        }

        $delivery_price = 0;
        $tax_price = $cartlist['tax_price'];
        $user = User::where('id', $store->created_by)->first();
        if ($user->type == 'admin') {
            $plan = Plan::find($user->plan_id);
        }
        if ($plan->shipping_method == 'on') {
            if (!empty($request->method_id)) {
                $del_charge = new CartController();
                $delivery_charge = $del_charge->get_shipping_method($request, $slug);
                $content = $delivery_charge->getContent();

                $data = json_decode($content, true);

                $delivery_price = $data['total_final_price'];

                $tax_price = $data['final_tax_price'];
            }
        } else {
            if (!empty($tax_price)) {
                $tax_price = $tax_price;
            } else {
                $tax_price = 0;
            }
        }

        // Order stock decrease start
        $prodduct_id_array = [];
        if (!empty($products)) {
            foreach ($products as $key => $product) {
                $prodduct_id_array = $product->product_id;

                $product_id = $product->product_id;
                $variant_id = $product->variant_id;
                $qtyy = !empty($product->qty) ? $product->qty : 0;

                $Product = Product::where('id', $product_id)->first();
                $datas = Product::find($product_id);
                if ($settings['stock_management'] ?? '' == 'on') {
                    if (!empty($product_id) && !empty($variant_id) && $product_id != 0 && $variant_id != 0) {
                        $ProductStock = ProductVariant::where('id', $variant_id)->where('product_id', $product_id)->first();
                        $variationOptions = explode(',', $ProductStock->variation_option);
                        $option = in_array('manage_stock', $variationOptions);
                        if (!empty($ProductStock)) {
                            if ($option == true) {
                                $remain_stock = $ProductStock->stock - $qtyy;
                                $ProductStock->stock = $remain_stock;
                                $ProductStock->save();

                                if ($ProductStock->stock <= $ProductStock->low_stock_threshold) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $ProductStock, $settings);
                                        }
                                    }
                                }
                                if ($ProductStock->stock <= $settings['out_of_stock_threshold']) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_out_of_stock($product, $ProductStock, $settings);
                                        }
                                    }
                                }
                            } else {
                                $remain_stock = $datas->product_stock - $qtyy;
                                $datas->product_stock = $remain_stock;
                                $datas->save();
                                if ($datas->product_stock <= $datas->low_stock_threshold) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_low_stock_threshold($product, $datas, $settings);
                                        }
                                    }
                                }
                                if ($datas->product_stock <= $settings['out_of_stock_threshold']) {
                                    if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock", json_decode($settings['notification']))) {
                                        if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                            Utility::variant_out_of_stock($product, $datas, $settings);
                                        }
                                    }
                                }
                                if ($datas->product_stock <= $settings['out_of_stock_threshold'] && $datas->stock_order_status == 'notify_customer') {
                                    //Stock Mail
                                    $order_email = $billing['email'];
                                    $owner = User::find($store->created_by);
                                    $ProductId    = '';

                                    try {
                                        $dArr = [
                                            'item_variable' => $Product->id,
                                            'product_name' => $Product->name,
                                            'customer_name' => $billing['firstname'],
                                        ];

                                        // Send Email
                                        $resp = Utility::sendEmailTemplate('Stock Status', $order_email, $dArr, $owner, $store, $ProductId);
                                    } catch (\Exception $e) {
                                        $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                                    }
                                    try {
                                        $mobile_no = $request['billing_info']['billing_user_telephone'];
                                        $customer_name = $request['billing_info']['firstname'];
                                        $msg =   __("Dear,$customer_name .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :$Product->name. ");
                                        $resp  = Utility::SendMsgs('Stock Status', $mobile_no, $msg);
                                    } catch (\Exception $e) {
                                        $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
                                    }
                                }
                            }
                        } else {
                            return $this->error(['message' => 'Product not found .']);
                        }
                    } elseif (!empty($product_id) && $product_id != 0) {

                        if (!empty($Product)) {
                            $remain_stock = $Product->product_stock - $qtyy;
                            $Product->product_stock = $remain_stock;
                            $Product->save();
                            if ($Product->product_stock <= $Product->low_stock_threshold) {
                                if (!empty(json_decode($settings['notification'])) && in_array("enable_low_stock", json_decode($settings['notification']))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::low_stock_threshold($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= $settings['out_of_stock_threshold']) {
                                if (!empty(json_decode($settings['notification'])) && in_array("enable_out_of_stock", json_decode($settings['notification']))) {
                                    if (isset($settings['twilio_setting_enabled']) && $settings['twilio_setting_enabled'] == "on") {
                                        Utility::out_of_stock($Product, $settings);
                                    }
                                }
                            }

                            if ($Product->product_stock <= $settings['out_of_stock_threshold'] && $Product->stock_order_status == 'notify_customer') {
                                //Stock Mail
                                $order_email = $billing['email'];
                                $owner = User::find($store->created_by);
                                $ProductId    = '';

                                try {
                                    $dArr = [
                                        'item_variable' => $Product->id,
                                        'product_name' => $Product->name,
                                        'customer_name' => $billing['firstname'],
                                    ];

                                    // Send Email
                                    $resp = Utility::sendEmailTemplate('Stock Status', $order_email, $dArr, $owner, $store, $ProductId);
                                } catch (\Exception $e) {
                                    $smtp_error = __('E-Mail has been not sent due to SMTP configuration');
                                }
                                try {
                                    $mobile_no = $request['billing_info']['billing_user_telephone'];
                                    $customer_name = $request['billing_info']['firstname'];
                                    $msg =   __("Dear,$customer_name .Hi,We are excited to inform you that the product you have been waiting for is now back in stock.Product Name: :$Product->name. ");
                                    $resp  = Utility::SendMsgs('Stock Status', $mobile_no, $msg);
                                } catch (\Exception $e) {
                                    $smtp_error = __('Invalid OAuth access token - Cannot parse access token');
                                }
                            }
                        } else {
                            return $this->error(['message' => 'Product not found .']);
                        }
                    } else {
                        return $this->error(['message' => 'Please fill proper product json field .']);
                    }
                }
                // remove from cart
                Cart::where('customer_id', $request->customer_id)->where('product_id', $product_id)->where('variant_id', $variant_id)->where('store_id', $store->id)->delete();
            }
            // Order stock decrease end

            // add in Order Coupon Detail table start
        }
        if (!empty($request->coupon_info)) {
            $coupon_data = $request->coupon_info;

            $discount_string = '-' . $coupon_data['coupon_discount_number'];
            $CURRENCY = Utility::GetValueByName('CURRENCY');
            $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME');
            if ($coupon_data['coupon_discount_type'] == 'flat') {
                $discount_string .= $CURRENCY;
            } else {
                $discount_string .= '%';
            }
            $discount_string .= ' ' . __('for all products');
            $discount = '-' . $coupon_data['coupon_discount_amount'];
            $discount_string2 = '(' . $discount . ' ' . $CURRENCY_NAME . ')';

            $order_array['coupon_info']['code'] = $coupon_data['coupon_code'];
            $order_array['coupon_info']['discount'] = $discount;
            $order_array['coupon_info']['discount_string'] = $discount_string;
            $order_array['coupon_info']['discount_string2'] = $discount_string2;
            $order_array['coupon_info']['price'] = SetNumber($coupon_data['coupon_final_amount']);
            $order_array['coupon_info']['discount_amount'] = SetNumber($coupon_data['coupon_discount_amount']);
        }

        $tax = Tax::find($tax_id);
        $tax_rate = 0;
        if (isset($tax)) {
            if ($tax->tax_methods()) {
                foreach (json_decode($tax->tax_methods()) as $method) {
                    $tax_rate += $method->tax_rate;
                }
            }
        }
        $order_array['tax']['tax_id'] = $tax_id ?? 0;
        $order_array['tax']['tax_name'] = $tax->name ?? 'Tax';
        $order_array['tax']['tax_price'] = $tax_price ?? 0;
        $order_array['tax']['tax_rate'] = $tax_rate ?? 0;
        // add in Order Tax Detail table end
        $order_array['product'] = $products;
        $order_array['billing_information']['name'] = $billing['firstname'] . ' ' . $billing['firstname'];
        $order_array['billing_information']['address'] = $billing['billing_address'];
        $order_array['billing_information']['email'] = $billing['email'];
        $order_array['billing_information']['phone'] = $billing['billing_user_telephone'];
        $order_array['billing_information']['country'] = $billing['billing_country'];
        $order_array['billing_information']['state'] = $billing['billing_state'];
        $order_array['billing_information']['city'] = $billing['billing_city'];
        $order_array['billing_information']['postecode'] = $billing['billing_postecode'];
        $order_array['delivery_information']['name'] = $billing['firstname'] . ' ' . $billing['firstname'];
        $order_array['delivery_information']['address'] = $billing['delivery_address'];
        $order_array['delivery_information']['email'] = $billing['email'];
        $order_array['delivery_information']['phone'] = $billing['billing_user_telephone'];
        $order_array['delivery_information']['country'] = $billing['delivery_country'];
        $order_array['delivery_information']['state'] = $billing['delivery_state'];
        $order_array['delivery_information']['city'] = $billing['delivery_city'];
        $order_array['delivery_information']['postecode'] = $billing['delivery_postcode'];

        $payment_data = Utility::payment_data($request->payment_type);
        $order_array['paymnet'] = empty('storage/' . $payment_data['image']) ? 'storage/' . $payment_data['image'] : Storage::url('uploads/payment/cod.png');

        $Shipping = Shipping::find($request->delivery_id);
        $delivery_image = '';
        if (!empty($Shipping)) {
            $delivery_image = $Shipping->image_path;
        } else {
            $delivery_image = Storage::url('uploads/delivery.png');
        }
        $order_array['delivery'] = $delivery_image;
        $order_array['delivery_charge'] = SetNumber($delivery_price);
        $order_array['subtotal'] = SetNumber($final_price);
        $order_array['final_price'] = SetNumber($cartlist_final_price);
        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        

        $user = Cache::remember('admin_details', 3600, function () {
            return User::where('type','admin')->first();
        });
        if ($user->type == 'admin') {
            $plan = Plan::find($user->plan_id);
        }
        $is_guest = 1;
        if (auth('customers')->check()) {
            $product_order_id  = $request->customer_id . date('YmdHis');
            $is_guest = 0;
        }
        $product_reward_point = 1;
        $product_order_id  = '0' . date('YmdHis');

        // add in  Order table  start
        $order = new Order();
        $order->product_order_id = $product_order_id;
        $order->order_date = date('Y-m-d H:i:s');
        $order->customer_id = !empty($request->customer_id) ? $request->customer_id : 0;
        $order->is_guest = $is_guest;
        $order->product_id = isset($prodduct_id_array) ? $prodduct_id_array : '';
        $order->product_json = json_encode($order_array['product']);
        $order->product_price = (float)$order_array['final_price'];
        $order->coupon_price = $order_array['coupon_info']['discount_amount'];
        $order->delivery_price = $order_array['delivery_charge'];
        $order->tax_price = $order_array['tax']['tax_price'];
        
        $order->currency_code = $currentCurrencyCode;
        $order->currency_symbol = $currentCurrencySymbol;


        if (!auth('customers')->user()) {
            if ($plan->shipping_method == "on") {
                $order->final_price = (float)$order_array['delivery_charge'];
            } else {
                $order->final_price =  (float)$order_array['final_price'] + $order_array['tax']['tax_price'];
            }
        } else {
            if ($plan->shipping_method == "on") {
                $order->final_price = (float)$order_array['delivery_charge'] + $order_array['tax']['tax_price'];
            } else {
                $order->final_price = (float) $order_array['final_price'] + $order_array['tax']['tax_price'];
            }
        }
        event(new AddAdditionalFields($order, $request->all(), $store));
        $order->payment_comment = !empty($request->payment_comment) ? $request->payment_comment : '-';
        $order->payment_type = $request->payment_type;
        // DERIVED, not asserted. This was the literal `'Paid'` on the public
        // POST /api/{slug}/order-save path: an unauthenticated caller could
        // create an order and have it recorded as paid with nothing having
        // asked a gateway whether any money moved. It is the same seam the two
        // PaymentController order writers use, so the four order-creating paths
        // now derive this status identically.
        //
        // Note `$order->final_price` is read AFTER the block above has set it,
        // so the amount the verification is checked against is the amount the
        // order is actually for.
        $order->payment_status = $this->paymentVerifier()->resolveOrderPaymentStatus(
            $request->payment_type ?? null,
            $this->paymentVerifier()->consumeVerification(),
            $order->final_price,
            __METHOD__
        );
        $order->delivery_id = $request['method_id'] ?? 0;
        $order->delivery_comment = !empty($request->shipping_comment) ? $request->shipping_comment : '-';
        $order->delivered_status = !empty($request->delivered_status) ? $request->delivered_status : '-';
        $order->reward_points = (float)$product_reward_point;
        $order->additional_note = !empty($request->additional_note) ? $request->additional_note : '-';
        $order->store_id = $store->id;
        $order->save();
        $billing_city_id = 0;
        if (!empty($billing['billing_city'])) {
            $cityy = City::where('name', $billing['billing_city'])->first();

            if (!empty($cityy)) {
                $billing_city_id = $cityy->id;
            } else {
                $new_billing_city = new City();
                $new_billing_city->name = $billing['billing_city'];
                $new_billing_city->state_id = $billing['billing_state'];
                $new_billing_city->country_id = $billing['billing_country'];
                $new_billing_city->save();
                $billing_city_id = $new_billing_city->id;
            }
        }

        $delivery_city_id = 0;
        if (!empty($billing['delivery_city'])) {
            $d_cityy = City::where('name', $billing['delivery_city'])->first();
            if (!empty($d_cityy)) {
                $delivery_city_id = $d_cityy->id;
            } else {
                $new_delivery_city = new City();
                $new_delivery_city->name = $billing['delivery_city'];
                $new_delivery_city->state_id = $billing['delivery_state'];
                $new_delivery_city->country_id = $billing['delivery_country'];
                $new_delivery_city->save();
                $delivery_city_id = $new_delivery_city->id;
            }
        }

        $OrderBillingDetail = new OrderBillingDetail();
        $OrderBillingDetail->order_id = $order->id;
        $OrderBillingDetail->product_order_id = $order->product_order_id;
        $OrderBillingDetail->first_name = !empty($billing['firstname']) ? $billing['firstname'] : '';
        $OrderBillingDetail->last_name = !empty($billing['lastname']) ? $billing['lastname'] : '';
        $OrderBillingDetail->email = !empty($billing['email']) ? $billing['email'] : '';
        $OrderBillingDetail->telephone = !empty($billing['billing_user_telephone']) ? $billing['billing_user_telephone'] : '';
        $OrderBillingDetail->address = !empty($billing['billing_address']) ? $billing['billing_address'] : '';
        $OrderBillingDetail->postcode = !empty($billing['billing_postecode']) ? $billing['billing_postecode'] : '';
        $OrderBillingDetail->country = !empty($billing['billing_country']) ? $billing['billing_country'] : '';
        $OrderBillingDetail->state = !empty($billing['billing_state']) ? $billing['billing_state'] : '';
        $OrderBillingDetail->city = $billing_city_id;
        
        $OrderBillingDetail->delivery_address = !empty($billing['delivery_address']) ? $billing['delivery_address'] : '';
        $OrderBillingDetail->delivery_city = $delivery_city_id;
        $OrderBillingDetail->delivery_postcode = !empty($billing['delivery_postcode']) ? $billing['delivery_postcode'] : '';
        $OrderBillingDetail->delivery_country = !empty($billing['delivery_country']) ? $billing['delivery_country'] : '';
        $OrderBillingDetail->delivery_state = !empty($billing['delivery_state']) ? $billing['delivery_state'] : '';
        $OrderBillingDetail->save();

        // add in Order Coupon Detail table start
        if (!empty($order_array['coupon_info'])) {
            $coupon_data = $order_array['coupon_info'];
            if (isset($coupon_data['coupon_id'])) {
                $Coupon = Coupon::find($coupon_data['coupon_id']);
                // coupon stock decrease end

                // Order Coupon history
                $OrderCouponDetail = new OrderCouponDetail();
                $OrderCouponDetail->order_id = $order->id;
                $OrderCouponDetail->product_order_id = $order->product_order_id;
                $OrderCouponDetail->coupon_id = $coupon_data['coupon_id'] ?? 0;
                $OrderCouponDetail->coupon_name = $coupon_data['coupon_name'] ?? '-';
                $OrderCouponDetail->coupon_code = $coupon_data['coupon_code'] ?? '-';
                $OrderCouponDetail->coupon_discount_type = $coupon_data['coupon_discount_type'] ?? '-';
                $OrderCouponDetail->coupon_discount_number = $coupon_data['coupon_discount_number'] ?? 0;
                $OrderCouponDetail->coupon_discount_amount = $coupon_data['coupon_discount_amount'] ?? 0;
                $OrderCouponDetail->coupon_final_amount = $coupon_data['coupon_final_amount'] ?? 0;
                
                $OrderCouponDetail->save();

                // Coupon history
                $UserCoupon = new UserCoupon();
                $UserCoupon->user_id = !empty($request->customer_id) ? $request->customer_id : null;
                $UserCoupon->coupon_id = $Coupon->id;
                $UserCoupon->amount = $coupon_data['coupon_discount_amount'];
                $UserCoupon->order_id = $order->id;
                $UserCoupon->date_used = now();
                
                $UserCoupon->save();

                $discount_string = '-' . $coupon_data['coupon_discount_amount'];
                $CURRENCY = Utility::GetValueByName('CURRENCY');
                $CURRENCY_NAME = Utility::GetValueByName('CURRENCY_NAME');
                if ($coupon_data['coupon_discount_type'] == 'flat') {
                    $discount_string .= $CURRENCY;
                } else {
                    $discount_string .= '%';
                }

                $discount_string .= ' ' . __('for all products');
                $order_array['coupon']['code'] = $coupon_data['coupon_code'];
                $order_array['coupon']['discount_string'] = $discount_string;
                $order_array['coupon']['price'] = SetNumber($coupon_data['coupon_final_amount']);
            }
        }
        // add in Order Coupon Detail table end
        if (isset($order_array['tax'])) {
            $OrderTaxDetail = new OrderTaxDetail();
            $OrderTaxDetail->order_id = $order->id;
            $OrderTaxDetail->product_order_id = $order->product_order_id;
            $OrderTaxDetail->tax_id = $order_array['tax_id'] ?? 0;
            $OrderTaxDetail->tax_name = $order_array['tax_name'] ?? 'Tax';
            $OrderTaxDetail->tax_discount_amount = $order_array['tax_price'] ?? 0;
            $OrderTaxDetail->tax_final_amount = $order_array['tax_price'] ?? 0;
            
            $OrderTaxDetail->save();
        }
        $order_array['order_id'] = $order->product_order_id ?? 0;

        return $this->success($order_array,  __('Order added successfully.'));
    }

    public function variant_list(Request $request, $slug = '')
    {
        $store = getStore($slug);
        if (empty($store)) {
            return $this->error(['message' => __('Store not found.')], __("Store not found."));
        }
        
        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();

        if (auth('customers')->user()) {
            $rules = [
                'product_id' => 'required',
                'variant' => 'required',
                'quantity' => 'required',
            ];
            $customer_id         = auth('customers')->user()->id;
        } else {
            $rules = [
                'customer_id' => 'required',
                'product_id' => 'required',
                'variant' => 'required',
                'quantity' => 'required',
            ];
            $customer_id         = $request->customer_id ?? null;
        }
        $customer = Customer::find($customer_id);
        if (!isset($customer) || empty($customer)) {
            return $this->error(['message' => __('Unauthenticated.')], __('Unauthenticated.'), 401);
        }
        $variantString = $request->variant;
        if (is_array($variantString)) {
            $variantString = implode(',', $variantString);
        }
        $variantString = trim($variantString, '[]');
        $pairStrings = explode(',', $variantString);
        $varint = [];
        foreach ($pairStrings as $pairString) {
            if (strpos($pairString, ':') !== false) {
                $pair = explode(':', $pairString, 2);
                $key = trim(trim($pair[0]), '"\'{}');
                if (isset($pair[1])) {
                    $value = trim(trim($pair[1]), '"\'{}');
                    $varint[$key] = $value;
                }
            }
        }

        $qty = $request->quantity;
        $product_id = $request->product_id;
        $product = Product::find($product_id);

        if (!empty($product)) {
            if ($product->variant_product == 0) {
                // no varint
                if (isset($settings['out_of_stock_threshold']) && ($product->product_stock < $settings['out_of_stock_threshold']) && $product->stock_order_status == 'not_allow') {
                    $return['status'] = 'error';
                    $return['message'] = __('Product has been reached max quantity.');
                } else {
                    $product_original_price = $product->original_price * $qty;
                    $product_final_price = $product->final_price * $qty;
                    $data['store_id'] = $store->id;
                    $data['product_tax_id'] = $product->tax_id;
                    $data['price'] = $product_final_price;
                    $data['product_original_price'] = $product_original_price;
                    $cart_array  = Tax::TaxCount($data);

                    $return['price'] = SetNumber($product_original_price);
                    $return['sale_price'] = SetNumber($product_final_price);
                    $return['original_price'] = SetNumber($cart_array['original_price']);
                    $return['final_price'] = SetNumber($cart_array['final_price']);
                    $return['currency_name'] = $cart_array['currency_name'];
                    $return['total_tax_price'] = SetNumber($cart_array['total_tax_price']);
                    return $this->error(['message' => 'Variant not found.'], __("Variant not found."));
                }
            } elseif ($product->variant_product == 1) {
                // has varint
                if ($varint) {
                    if (is_array($varint)) {
                        $variant_name = implode('-', $varint);
                    } else {
                        $variant_name = $varint;
                    }
                } else {
                    if (is_array($variantString)) {
                        $variant_name = implode('-', $variantString);
                    } else {
                        $variantArray = explode(',', $variantString);
                        $variant_name = implode('-', $variantArray);
                    }
                }
                $product->setAttribute('variantName', $variant_name);
                $ProductStock = ProductVariant::where('product_id', $product_id)
                    ->where('variant', $variant_name)
                    ->first();
                if ($ProductStock) {
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

                        $product_original_price = $product->original_price * $qty;
                        $product_final_price = $product->final_price * $qty;

                        if ($option == true) {
                            $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : 0;
                        } else {
                            $variat_stock = !empty($ProductStock->stock) ? $ProductStock->stock : $product->product_stock;
                        }
                        $data['store_id'] = $store->id;
                        $data['product_tax_id'] = $product->tax_id;
                        $data['price'] = $product_final_price;
                        $data['product_original_price'] = $product_original_price;
                        $cart_array  = Tax::TaxCount($data);

                        $return['price'] = SetNumber($product_original_price);
                        $return['sale_price'] = SetNumber($product_final_price);
                        $return['currency_name'] = $cart_array['currency_name'];
                        $return['currency'] = $cart_array['currency'];
                        $return['product_stock'] = !empty($variat_stock) ? $variat_stock : 0;
                        $return['stock_order_status'] = !empty($ProductStock->stock_status) ? $ProductStock->stock_status : '-';
                        $return['description'] = !empty($ProductStock->description) ? $ProductStock->description : $product->descripion;
                    }
                }
            } else {
            }
        } else {
            return $this->error(['message' => 'Variant not found.'], __("Variant not found."));
        }
        if (!empty($return)) {
            return $this->success($return, __("Variant get successfully."));
        } else {
            return $this->error(['message' => __('Variant not found.')], __("Variant not found."));
        }
    }
}