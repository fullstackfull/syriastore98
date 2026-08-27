<?php

namespace Workdo\MarketPlaceList\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Models\Store;
use App\Models\Utility;
use Qirolab\Theme\Theme;
use Illuminate\Http\Response;
use App\Models\Addon;
use Illuminate\Support\Facades\Artisan;
use App\Models\Plan;
use App\Models\User;
use App\Models\Setting;
use Workdo\MarketPlaceList\app\Models\StoreBusinessHour;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class MarketPlaceListController extends Controller
{
    public function marketPlaceList($slug)
    {
        $store = getStore($slug);
        if (empty($store)) {
            return redirect()->route('landing_page', $slug);
        }
        
        $marketPlaceListEnable = Utility::GetValueByName('market_place_list_enable',$store->id);
        
        if (isset($marketPlaceListEnable) && $marketPlaceListEnable != 'off') {
            $stores = Store::where('slug', '!=' , $slug)->where('created_by', $store->created_by)->where('id', '!=', 1)->get();
            $addon_themes = Addon::get();
            $theme = Utility::BuyMoreTheme();            
            
            return view('market-place-list::marketplacelistpage', compact('stores', 'theme', 'addon_themes'));
        } else {
            return redirect()->route('landing_page', $slug);
        }
    }

    public function storeHoursSettings(Request $request)
    {
        $requestAll = $request->all();
        $days = StoreBusinessHour::$days;
        $business_hours = [];
        session()->put('app_setting_tab', $request->app_setting_tab);
        foreach ($days as $k => $day) {
            $time['days'] = isset($requestAll['days_' . $k]) ? 'on' : 'off';
            $time['start_time'] = $requestAll['start-' . $k];
            $time['end_time'] = $requestAll['end-' . $k];
            $business_hours[$k] = $time;
        }
        $business_hours = json_encode($business_hours);
        $businessHours = StoreBusinessHour::where('store_id', getCurrentStore())->first();
        if (!is_null($businessHours)) {
            $businessHours->content = $business_hours;
            $businessHours->created_by = \Auth::user()->creatorId();
            $businessHours->save();
        } else {
            StoreBusinessHour::create([
                'store_id' => getCurrentStore(),
                'content' => $business_hours,
                'created_by' => \Auth::user()->creatorId()
            ]);
        }
        
        return redirect()->back()->with('success', __('Settings successfully updated.'));
    }


    public function getstore(Request $request)
    {
        $storeBusinessHours = StoreBusinessHour::where('store_id', $request->store_id)->first();

        return $storeBusinessHours;
    }
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        // return view('marketplacelist::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {

        // return view('marketplacelist::create');
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
        return view('market-place-list::marketplacelistpage');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('market-place-list::edit');
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
