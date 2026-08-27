<?php

namespace Workdo\StoreLocator\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\StoreLocator\app\Models\StoreLocator;

class StoreLocatorController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        return view('store-locator::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('store-locator::create');
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
        return view('store-locator::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('store-locator::edit');
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

    public function updateData(Request $request)
    {
        $theme_id = !empty(APP_THEME()) ?  APP_THEME() : '';
        session()->put('app_setting_tab', $request->app_setting_tab ?? 'pills-store_locator-tab');
        $settings = StoreLocator::where('created_by', auth()->user()->id)->where('store_id', getCurrentStore())->first();
        $storeLocateApiKey = '';
        if (!empty($settings)) {
            $storeLocateApiKey = $settings->store_locator_api_key ?? '';
        }
        $apiKey = $storeLocateApiKey;
        if ($request->store_address) {
            $address = urlencode($request->store_address);
            $geocodeEndpoint = "https://maps.googleapis.com/maps/api/geocode/json?address=$address&key=$apiKey";
            $geolocationData = json_decode(file_get_contents($geocodeEndpoint), true);
        }

        if ($request->store_address && $geolocationData && $geolocationData['status'] === 'OK' && !empty($geolocationData['results'])) {
            $firstResult = $geolocationData['results'][0];
            $latitude = $firstResult['geometry']['location']['lat'] ?? '';
            $longitude = $firstResult['geometry']['location']['lng'] ?? '';
            $pincode = $firstResult['address_components'][6]['long_name'] ?? '';
            $storeLatitude = $latitude;
            $storeLongitude = $longitude;
        } else {
            $storeLatitude = '';
            $storeLongitude = '';
            $pincode = '';
        }

        $locator_enable = $settings->store_locator_enabled ?? 'on';
        $storeAddress = $request->store_address;
        $storeLongitude = $storeLongitude;
        $storeLatitude = $storeLatitude;

        $settingQuery = StoreLocator::query();

        $storeLocator = $settingQuery->updateOrCreate(
            [
                'created_by' => auth()->user()->id,
                'store_id'      => getCurrentStore()
            ],
            [
                'store_locator_enabled' => $locator_enable,
                'store_locator_api_key' => $storeLocateApiKey,
                'store_address' => $storeAddress,
                'store_latitude' => $storeLatitude,
                'store_longitude' => $storeLongitude,
                'pincode' => $pincode,
                'created_by' => auth()->user()->id,
                'store_id' => getCurrentStore(),
            ]
        );

        return redirect()->back()->with('success', __('Store Locator setting successfully updated.'));
    }

    public function updateSettingsData(Request $request)
    {
        session()->put(['setting_tab' => 'store_locator_setting']);
        $theme_id = !empty(APP_THEME()) ?  APP_THEME() : '';

        $settings = StoreLocator::where('created_by', auth()->user()->id)->where('store_id', getCurrentStore())->first();
        $storeLocateApiKey = $request->store_locator_api_key;
        if (!empty($settings)) {
            $storeLocateApiKey = $settings->store_locator_api_key;
            if (empty($storeLocateApiKey)) {
                $storeLocateApiKey = $request->store_locator_api_key;
            }
        }
        $apiKey = $storeLocateApiKey;
        $locator_enable = $request->store_locator_enabled;

        $settingQuery = StoreLocator::query();

        $storeLocator = $settingQuery->updateOrCreate(
            [
                'created_by' => auth()->user()->id,
                'store_id'      => getCurrentStore()
            ],
            [
                'store_locator_enabled' => $locator_enable,
                'store_locator_api_key' => $storeLocateApiKey,
                'created_by' => auth()->user()->id,
                'store_id' => getCurrentStore(),
            ]
        );

        return redirect()->back()->with('success', __('Store Locator setting successfully updated.'));
    }

    public function settingForm(Request $request)
    {
        try {
            $settings = StoreLocator::where('created_by', auth()->user()->id)->where('store_id', getCurrentStore())->first();
            $html = view('store-locator::pages.tab_form', compact('settings'))->render();

            return response()->json([
                'is_success' => true,
                "msg" => __("Store Locator setting form get successfully."),
                "data" => ['content' => $html]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'is_success' => false,
                "msg" => __('Something went wrong!'),
                "data" => ['content' => null]
            ]);
        }
    }
}
