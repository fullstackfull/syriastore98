<?php

namespace Workdo\BoostSales\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Cache;
use App\Traits\ApiResponser;

class BoostSalesController extends Controller
{
    use ApiResponser;
    public function boostSalesSettings(Request $request)
    {
        $post = $request->all();
        unset($post['_token']);
        session()->put('app_setting_tab', $request->app_setting_tab);
        if (!empty($request->boost_sale_product_id)) {
            $post['boost_sale_product_id'] = implode(',', $request->boost_sale_product_id);
        } else {
            $post['boost_sale_product_id'] = '';
        }
        $post['boost_sales_enabled'] = $request->boost_sales_enabled ?? 'off';
        $post['boost_sales_product_display'] = $request->boost_sales_product_display;
        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
                [
                    'name' => $key,
                    'store_id' => getCurrentStore()
                ],
                [
                    'value' => $data,
                    'name' => $key,
                    'store_id' => getCurrentStore(),
                    'created_by' => \Auth::user()->id,
                ]
            );
        }

        return redirect()->back()->with('success', __('Boost Sales settings successfully saved.'));
    }

    public function product_cartlist(Request $request, $slug)
    {

        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        
        $variant_id = $request->variant_id;
        $qty = $request->qty;
        $product_ids = $request->product_ids;
        $enable_product = $request->enable_product;

        if (!auth('customers')->user()) {
            $cart_cookie = Cookie::get('cart');
            $cart_array = !empty($cart_cookie) ? json_decode($cart_cookie, true) : [];
            if ($enable_product) {
                $session_id = Cookie::get('cart');
            if (empty($session_id) || $session_id != 'null') {
                $session_id = session()->getId();
            }
            if ($enable_product) {
                foreach ($enable_product as $product_id) {
                    if (in_array($product_id, $product_ids)) {
                        $cart = Cart::where('cookie_session_id', $session_id)
                            ->where('product_id', $product_id)
                            ->where('variant_id', $variant_id)
                            ->where('store_id', $store->id)
                            ->first();
                        if ($cart) {
                            $already_in_cart[] = $cart->product_id;                            
                            continue;
                        } else {
                            $product = Product::find($product_id);
                            Cart::create([
                                'customer_id' => null,
                                'product_id' => $product_id,
                                'variant_id' => $variant_id,
                                'qty'        => 1,
                                'price'      => floatval($product->final_price) * 1,
                                'store_id' => $store->id,
                                'cookie_session_id' => $session_id
                            ]);

                        }
                    }
                }

                Cookie::queue('cart', $session_id, 1440);
                if (!empty($already_in_cart)) {
                    $products_names = implode(', ', $already_in_cart);
                    return Utility::error(['message' => 'Products ' . $product->name . ' already in cart.']);
                } else {
                    return Utility::success(['message' => __('Products added successfully.')]);
                }
            } else {
                return Utility::error(['message' => __('Choose items to buy together.')]);
            }
        } else {
            $customer_id = auth('customers')->user()->id;
            $settings = Utility::Setting($store->id);

            $success_message = '';

            if ($enable_product) {
                foreach ($enable_product as $product_id_str) {
                    $product_id = substr($product_id_str, strpos($product_id_str, '_') + 1);
                    if (in_array($product_id, $product_ids)) {
                        $product = Product::find($product_id);
                        if (!$product) {
                            return Utility::error(['message' => __('Product not found.')]);
                        }

                        $final_price = 0;
                        if (!empty($request->attribute_id) || $request->attribute_id != 0) {
                            $ProductVariant = ProductVariant::where('id', $request->attribute_id)
                                ->where('product_id', $request->product_id)
                                ->first();

                            $variationOptions = explode(',', $ProductVariant->variation_option);
                            $option = in_array('manage_stock', $variationOptions);
                            if ($option == true) {
                                if (empty($ProductVariant)) {
                                    return $this->error(['message' => __('Product not found.')], __('Product not found.'));
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
                                    $product_stock_datas = ProductVariant::find($request->variant_id);
                                    $product->setAttribute('variantId', $request->variant_id);
                                    $var_stock = !empty($product_stock_datas->stock) ? $product_stock_datas->stock : $product->product_stock;

                                    if (empty($request->variant_id) || $request->variant_id == 0) { {
                                            return $this->error(['message' => __('Please Select a variant in a product.')], __('Please Select a variant in a product.'));
                                        }
                                    } else if ($var_stock <= $settings['out_of_stock_threshold'] && $product->stock_order_status == 'not_allow') {
                                        return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    } else {
                                        $product_stock_data = ProductVariant::find($request->variant_id);
                                        if ($product_stock_data->stock_status == 'out_of_stock') {
                                            return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                        }
                                    }
                                } else {
                                    if ($product->product_stock <= $settings['out_of_stock_threshold'] && $product->stock_order_status == 'not_allow') {
                                        return $this->error(['message' => __('Product has out of stock.')], __('Product has out of stock.'));
                                    }
                                }
                                $final_price = floatval($product->final_price) * floatval($request->qty);
                            } else {
                                return $this->error(['message' => __('Product not found.')], __('Product not found.'));
                            }
                        }
                        $added_cart = Cart::where('customer_id', $customer_id)
                            ->where('product_id', $product_id)
                            ->where('variant_id', $variant_id)
                            ->where('store_id', $store->id)
                            ->first();

                        if ($added_cart) {
                            $success_message = __('Products already added into the cart.');
                        } else {
                            $cart_item = new Cart();
                            $cart_item->customer_id = $customer_id;
                            $cart_item->product_id = $product_id;
                            $cart_item->variant_id = $variant_id;
                            $cart_item->qty = $qty;
                            $cart_item->price = $final_price;
                            
                            $cart_item->store_id = $store->id;
                            $cart_item->save();
                            $success_message = __('Products added successfully.');
                        }
                    }
                }
                return Utility::success(['message' => $success_message]);
            } else {
                return Utility::error(['message' => __('Choose items to buy together.')]);
            }

        }
        }
    }


    public function product_price(Request $request, $slug)
    {
        $product_id = Product::find($request->product_id);
        $store = Store::where('slug', request()->segment(1))->first();
        $tax_price = $total_orignal_price = 0;
        $customer_id = auth('customers')->user()->id ?? 0;
        $DeliveryAddress = \App\Models\DeliveryAddress::where('customer_id', $customer_id)->first();
        $city_id = isset($DeliveryAddress->city_id) ? $DeliveryAddress->city_id : '';
        $state_id = isset($DeliveryAddress->state_id) ? $DeliveryAddress->state_id : '';
        $country = isset($DeliveryAddress->country_id) ? $DeliveryAddress->country_id : '';
        if (isset($product_id->tax_id)) {
            $tax_price += \App\Models\Cart::getProductTaxAmount($product_id->tax_id, $product_id->sale_price, $store->id, $city_id, $state_id, $country, true);
        } else {
            $tax_price += 0;
        }
        $tax_option = \App\Models\TaxOption::where('store_id', $store->id)
            ->pluck('value', 'name')->toArray();

        if (isset($tax_option['round_tax']) && $tax_option['round_tax'] == 1) {
            $tax_price = round($tax_price);
        }

        if ($request->product_id_selected == 1) {
            $subtotal = $request->price - $product_id->sale_price;
            if (isset($product_id)) {
                if ($product_id->tax_id !== null && $customer_id != 0) {
                    $data['store_id'] = $store->id;
                    $data['product_tax_id'] = $product_id->tax_id;
                    $data['sub_total'] = $subtotal;
                    $data['product_original_price'] = $product_id['sale_price'];
                    $taxes = \App\Models\Tax::TaxCount($data);
                    $tax_price = SetNumber($taxes['total_tax_price']);
                }
            }
            if ($customer_id != 0) {
                $total_tax = $tax_price;
            } else {
                $total_tax = SetNumber($request->tax - $tax_price);
            }
            $data = [
                'subtotal' => $subtotal,
                'total_tax' => $total_tax,
                'total_price' => $subtotal + $total_tax,
            ];
            return $data;
        } else {
            $subtotal = $request->price + $product_id->sale_price;
            if (isset($product_id)) {
                if ($product_id->tax_id !== null && $customer_id != 0) {
                    $data['store_id'] = $store->id;
                    $data['product_tax_id'] = $product_id->tax_id;
                    $data['sub_total'] = $subtotal;
                    $data['product_original_price'] = $product_id['sale_price'];
                    $taxes = \App\Models\Tax::TaxCount($data);
                    $tax_price = SetNumber($taxes['total_tax_price']);
                }
            }
            if ($customer_id != 0) {
                $total_tax = $tax_price;
            } else {
                $total_tax = SetNumber($request->tax + $tax_price);
            }
            $data = [
                'subtotal' => $subtotal,
                'total_tax' => $total_tax,
                'total_price' => $subtotal + $total_tax,
            ];
            return $data;
        }
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('boostsales::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('boostsales::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        //
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('boostsales::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('boostsales::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
    }
}

