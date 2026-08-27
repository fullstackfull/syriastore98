<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Store;
use App\Models\Utility;
use App\Models\WoocommerceConection;
use Illuminate\Http\Request;
use Codexshaper\WooCommerce\Facades\Coupon;
use Yajra\DataTables\Facades\DataTables;

class WoocomCouponController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Woocommerce Coupon'))
        {
            $setting = getAdminAllsetting();
            if(isset($setting['woocommerce_setting_enabled']) && $setting['woocommerce_setting_enabled'] == 'on')
            {
                try{
                    $theme_name = !empty(APP_THEME()) ? APP_THEME() : env('DATA_INSERT_APP_THEME');
                    $woocommerce_store_url = Utility::GetValueByName('woocommerce_store_url',$theme_name, getCurrentStore());
                    $woocommerce_consumer_secret = Utility::GetValueByName('woocommerce_consumer_secret',$theme_name, getCurrentStore());
                    $woocommerce_consumer_key = Utility::GetValueByName('woocommerce_consumer_key',$theme_name, getCurrentStore());

                    config(['woocommerce.store_url' => $woocommerce_store_url]);
                    config(['woocommerce.consumer_key' => $woocommerce_consumer_key]);
                    config(['woocommerce.consumer_secret' => $woocommerce_consumer_secret]);

                    // Fetch all products using pagination
                    $jsonData = collect(); // Initialize an empty collection
                    $page = 1;
                    $perPage = 100;
                    do {
                        // Fetch products from WooCommerce API, with pagination
                        $coupons = Coupon::all(['per_page' => $perPage, 'page' => $page]);
                        $jsonData = $jsonData->merge($coupons); // Append the new page data to the collection
                        $page++;
                    } while (count($coupons) > 0); // Continue fetching until no products are returned

                    // Check if the request is for datatable
                    if (request()->ajax()) {
                        $upddata = WoocommerceConection::where('store_id', getCurrentStore())->where('module','=','coupon')->get()->pluck('woocomerce_id')->toArray();
                        return DataTables::of($jsonData)
                            ->addColumn('name', function ($data) {
                                return !empty($data->code) ? $data->code : "-";
                            })
                            ->editColumn('amount', function ($data) {
                                // Determine the icon based on the discount type
                                if ($data->discount_type == 'fixed_cart') {
                                    $icon = 'ti ti-currency-dollar';
                                } else {
                                    $icon = 'ti ti-percentage';
                                }
                            
                                // Return the amount with the icon and the "Discount" text
                                return round($data->amount) . ' <i class="' . $icon . '"></i> ' . __('Discount');
                            })
                            ->editColumn('usage_limit_per_user', function ($data) {
                                return !empty($data->usage_limit_per_user) ? $data->usage_limit_per_user : "-1";
                            })
                            ->editColumn('date_expires', function ($data) {
                                return Utility::dateFormat($data->date_expires);
                            })
                            ->addColumn('action', function ($data) use ($upddata) {
                                return view('woocommerce.coupon_action', compact('data','upddata'));
                            })
                            ->rawColumns(['name','code','amount','usage_limit_per_user','date_expires','action'])
                            ->make(true);
                    }
                    
                    return view('woocommerce.coupon');
                }
                catch(\Exception $e){
                    return redirect()->back()->with('error' , 'Something went wrong.');
                }
            }else{
                return redirect()->back()->with('error', __('Permission denied.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create($id)
    {

    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
         
        if (auth()->user() && auth()->user()->isAbleTo('Create Woocommerce Coupon'))
        {
            $theme_name = !empty(APP_THEME()) ? APP_THEME() : env('DATA_INSERT_APP_THEME');
            $woocommerce_store_url = Utility::GetValueByName('woocommerce_store_url',$theme_name, getCurrentStore());
            $woocommerce_consumer_secret = Utility::GetValueByName('woocommerce_consumer_secret',$theme_name, getCurrentStore());
            $woocommerce_consumer_key = Utility::GetValueByName('woocommerce_consumer_key',$theme_name, getCurrentStore());

            config(['woocommerce.store_url' => $woocommerce_store_url]);
            config(['woocommerce.consumer_key' => $woocommerce_consumer_key]);
            config(['woocommerce.consumer_secret' => $woocommerce_consumer_secret]);

            $jsonData = Coupon::find($id);


            if (!empty($jsonData)) {
                $coupon                    = new \App\Models\Coupon();
                $coupon->coupon_name       = $jsonData['code'];
                $coupon->coupon_type       = $jsonData['discount_type'];
                $coupon->discount_amount   = $jsonData['amount'];
                $coupon->coupon_limit      =  !empty($jsonData['usage_limit_per_user']) ? $jsonData['usage_limit_per_user'] : -1;
                $coupon->coupon_expiry_date= $jsonData['date_expires'];
                $coupon->coupon_code       = $jsonData['code'];
                $coupon->status            = 1;
                $coupon->store_id          = getCurrentStore();
                $coupon->save();


                $Coupon                   = new WoocommerceConection();
                $Coupon->store_id         = getCurrentStore();
                $Coupon->module           = 'coupon';
                $Coupon->woocomerce_id    = $jsonData['id'];
                $Coupon->original_id      =$coupon->id;
                $Coupon->save();
                return redirect()->back()->with('success', __('Coupon successfully Add.'));

            } else {

                return redirect()->back()->with('error', __('Coupon Not Found.'));
            }
        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
         
        if(auth()->user() && auth()->user()->isAbleTo('Edit Woocommerce Coupon'))
        {
            $theme_name = !empty(APP_THEME()) ? APP_THEME() : env('DATA_INSERT_APP_THEME');
            $woocommerce_store_url = Utility::GetValueByName('woocommerce_store_url',$theme_name, getCurrentStore());
            $woocommerce_consumer_secret = Utility::GetValueByName('woocommerce_consumer_secret',$theme_name, getCurrentStore());
            $woocommerce_consumer_key = Utility::GetValueByName('woocommerce_consumer_key',$theme_name, getCurrentStore());

            config(['woocommerce.store_url' => $woocommerce_store_url]);
            config(['woocommerce.consumer_key' => $woocommerce_consumer_key]);
            config(['woocommerce.consumer_secret' => $woocommerce_consumer_secret]);

            $jsonData = Coupon::find($id);

            $store_id = getStoreById(getCurrentStore());
            $upddata = WoocommerceConection::where('store_id',getCurrentStore())->where('module','=','coupon')->where('woocomerce_id' , $id)->first();
            $original_id = $upddata->original_id;
            $coupon = \App\Models\Coupon::find($original_id);
            if (!empty($jsonData)) {
            $coupon->coupon_name       = $jsonData['code'];
            $coupon->coupon_type       = $jsonData['discount_type'];
            $coupon->discount_amount   = $jsonData['amount'];
            $coupon->coupon_limit      =  !empty($jsonData['usage_limit_per_user']) ? $jsonData['usage_limit_per_user'] : '';
            $coupon->coupon_expiry_date= $jsonData['date_expires'];
            $coupon->coupon_code       = $jsonData['code'];
            $coupon->status            = 1;
            $coupon->store_id          = getCurrentStore();

            $coupon->save();
            return redirect()->back()->with('success', __('Coupon update successfully.'));
            }
            else {

                return redirect()->back()->with('error', __('Coupon Not Found.'));

            }

        }
        else
        {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
