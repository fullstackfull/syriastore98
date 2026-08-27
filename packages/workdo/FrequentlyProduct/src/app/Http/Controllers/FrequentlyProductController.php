<?php

namespace workdo\FrequentlyProduct\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use \App\Models\Store;
use \App\Models\Cart;
use \App\Models\Product;
use \App\Models\Utility;
use App\Models\Setting;
use App\Http\Controllers\Api\ApiController;
use App\Models\ProductVariant;
use \Modules\FrequentlyProduct\app\Models\FrequentlyProduct;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Cache;
use App\Traits\ApiResponser;

class FrequentlyProductController extends Controller
{
    use ApiResponser;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('frequently-product::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('frequently-product::create');
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
        return view('frequently-product::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('frequently-product::edit');
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

    public function product_cartlist(Request $request, $slug)
    {

        $slug = !empty($slug) ? $slug : '';
        $store = getStore($slug);
        $theme_id = $store->theme_id;

        $variant_id = $request->variant_id;
        $qty = $request->qty;
        $product_ids = $request->product_ids;
        $enable_product = $request->enable_product;

        if (!auth('customers')->user()) {
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
                    return Utility::error(['message' => $products_names . ' already in cart.']);
                } else {
                    return Utility::success(['message' => 'Products added successfully.']);
                }
            } else {
                return Utility::error(['message' => 'Choose items to buy together.']);
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
                            $success_message = 'Products already added into the cart.';
                        } else {
                            $cart_item = new Cart();
                            $cart_item->customer_id = $customer_id;
                            $cart_item->product_id = $product_id;
                            $cart_item->variant_id = $variant_id;
                            $cart_item->qty = $qty;
                            $cart_item->price = $final_price;
                            
                            $cart_item->store_id = $store->id;
                            $cart_item->save();
                            $success_message = 'Products added successfully.';
                        }
                    }
                }
                return Utility::success(['message' => $success_message]);
            } else {
                return Utility::error(['message' => 'Choose items to buy together.']);
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
            $tax_price += \App\Models\Cart::getProductTaxAmount($product_id->tax_id, $product_id->sale_price, $store->id, $store->theme_id, $city_id, $state_id, $country, true);
        } else {
            $tax_price += 0;
        }
        $tax_option = \App\Models\TaxOption::where('store_id', $store->id)
            ->pluck('value', 'name')->toArray();

        if (isset($tax_option['round_tax']) && $tax_option['round_tax'] == 1) {
            $tax_price = round($tax_price);
        }
        if ($request->product_selected == 1) {
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

    public function frequentlySetting(Request $request)
    {
        session()->put(['setting_tab' => 'frequently_product_setting']);
        if (auth()->user()->isAbleTo('Manage Frequently Product')) {
            $post = [];
            $post['frequently_product_limit'] = $request->frequently_product_limit;

            $settingQuery = Setting::query();
            foreach ($post as $key => $data) {
                $status = (clone $settingQuery)->updateOrCreate(
                    [
                        'name' => $key,
                        
                        'store_id' => getCurrentStore()
                    ],
                    [
                        'value' => $data,
                        'name' => $key,
                        
                        'store_id' => getCurrentStore(),
                        'created_by' => auth()->user()->id,
                    ]
                );
            }

            if (isset($status)) {
                return redirect()->back()->with('success', __('Frequently product settings updated successfully'));
            } else {
                return redirect()->back()->with('error', __('Something is wrong'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function settingForm(Request $request)
    {
        try {
            $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
            $html = view('frequently-product::pages.tab_form', compact('setting'))->render();

            // Return JSON response
            return response()->json([
                'is_success' => true,
                "msg" => __("Frequently product form get successfully."),
                "data" => ['content' => $html]
            ]);
        } catch (\Exception $e) {
            // Return JSON response
            return response()->json([
                'is_success' => false,
                "msg" => __('Something went wrong!'),
                "data" => ['content' => null]
            ]);
        }
    }
}
