<?php

namespace Workdo\SkipCart\app\Http\Controllers;

use App\Http\Controllers\Api\ApiController;
use App\Models\Cart;
use App\Models\Country;
use App\Models\Setting;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Cookie;
use Illuminate\Support\Facades\Cache;

class SkipCartController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        return view('skip-cart::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('skip-cart::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('skip-cart::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('skip-cart::edit');
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        //
    }

    public function SkipCartSettings(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Skip Cart Setting')) {
            $theme_id = !empty(APP_THEME()) ? APP_THEME() : '';
            session()->put('app_setting_tab', $request->app_setting_tab ?? 'pills-skip_cart-tab');

            $post['enable_skip_cart'] = $request->enable_skip_cart ?? '';
            $post['skip_cart_checkout_setting'] = $request->skip_cart_checkout_setting;
            $post['skip_cart_button_text'] = $request->skip_cart_button_text;
            $post['skip_cart_button_link'] = $request->skip_cart_button_link;
            $post['skip_cart_sticky_position'] = $request->skip_cart_sticky_position;

            $settingQuery = Setting::query();
            foreach ($post as $key => $data) {
                $status = (clone $settingQuery)->updateOrCreate(
                    [
                        'name' => $key,
                        'store_id'      => getCurrentStore(),
                        'created_by'=>auth()->user()->id
                    ],
                    [
                        'value'         => $data,
                        'name'          => $key,
                        'store_id'      => getCurrentStore(),
                        'created_by'    => auth()->user()->id,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]
                );
            }
            if (isset($status)) {
                return redirect()->back()->with('success', __('Skip Cart setting successfully updated.'));
            } else {
                return redirect()->back()->with('error', __('Something is wrong'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    }

    public function OnPagecheckout(Request $request, $slug)
    {
        $store = getStore($slug);
        if (!$store) {
            abort(404);
        }
        
        $param = [
            'customer_id' => !empty(\Auth::guard('customers')->user()) ? \Auth::guard('customers')->user()->id : 0
        ];
        $request->merge($param);
        $api = new ApiController();

        $address_list_data = $api->address_list($request);
        $address_list = $address_list_data->getData();


        $country_option = Country::orderBy('name','ASC')->pluck('name', 'id')->prepend('Select Country', 0);
        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
       

        return view('skip-cart::theme.cart-checkout',compact('store','address_list','country_option','settings'));
    }

    public function emptyCart(Request $request)
    {
        if (\Auth::guard('customers')->check()) {
            Cart::where('customer_id', $request->customer_id)->delete();
        } else {
            $cart_array = [];
            $cart_json = json_encode($cart_array);
            Cookie::queue('cart', $cart_json, 1440);
        }
        return response()->json(['status' => 'success']);
    }

    public function CartView(Request $request,$slug)
    {
        if ($request->ajax()) {
            $store = getStore($slug);
            if (!$store) {
                abort(404);
            }
            
            $param = [
                'customer_id' => !empty(\Auth::guard('customers')->user()) ? \Auth::guard('customers')->user()->id : 0
            ];
            $request->merge($param);
            $api = new ApiController();

            $address_list_data = $api->address_list($request);
            $address_list = $address_list_data->getData();


            $country_option = Country::orderBy('name','ASC')->pluck('name', 'id')->prepend('Select Country', 0);
            $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();
            
            if (auth('customers')->guest()) {
                $response = Cart::cart_list_cookie($request->all(),$store->id);
                $response = json_decode(json_encode($response));
            } else {
                $request->merge(['customer_id' => auth('customers')->user()->id, 'store_id' => $store->id, 'slug' => $store->slug,'status' => true]);

                $api = new ApiController();
                $data = $api->cart_list($request, $store->slug);
                $response = $data->getData();
            }
            $product_list = isset($response->data->product_list) ? (array)$response->data->product_list : [];
            $hasProducts = count($product_list) > 0;
            $data = is_array($data) ? $data : [];
            $sqlData = is_array($sqlData) ? $sqlData : [];
            if ($hasProducts) {
                return view('skip-cart::theme.sticky-checkout-view',compact('store','address_list','country_option','settings','response','slug'))->render();
            }
        }
    }
}
