<?php

namespace Workdo\RewardClubPoint\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Workdo\RewardClubPoint\app\Models\RewardClubPoint;

class RewardClubPointController extends Controller
{
    /**
     * Display a listing of the resource.
     */

    public function clubPointSettings(Request $request)
    {
        $theme_id = !empty(APP_THEME()) ? APP_THEME() : 'stylique';
        $validator = \Validator::make(
            $request->all(),
            [
                'order_point_price'         => 'required',
                'order_points'              => 'required',
                'reward_points'             => 'required',
                'reward_point_price'        => 'required',
                'checkout_points'           => 'required',
                'checkout_price'            => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $post = $request->all();
        unset($post['_token']);
        session()->put('app_setting_tab', $request->app_setting_tab ?? 'pills-club_point-tab');
        $post['order_point_price']      = $request->order_point_price;
        $post['order_points']           = $request->order_points;
        $post['reward_points']          = $request->reward_points;
        $post['reward_point_price']     = $request->reward_point_price;
        $post['checkout_points']        = $request->checkout_points;
        $post['checkout_price']         = $request->checkout_price;
        $post['club_point_enabled']     = $request->club_point_enabled ?? 'off';
        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
                ['name' => $key,
                'store_id'      => getCurrentStore()
            ],
                [
                    'value'         => $data,
                    'name'          => $key,
                    'store_id'      => getCurrentStore(),
                    'created_by'    => \Auth::user()->id,
                ]
            );
        }

        return redirect()->back()->with('success', 'Reward/Club Point settings successfully saved.');
    }

    public function clubPointHistory($customerId)
    {
        $pointTransections = RewardClubPoint::with('orderData')->where('customer_id',$customerId)->orderBy('created_at', 'desc')->get();
        $customer = Customer::find($customerId);
        return view('reward-club-point::admin.clubPointTransections',compact('pointTransections','customer'));
    }


  public function applyPoints(Request $request, $slug)
{
    $store = getStore($slug);
    $customer = auth('customers')->user();
    $setting = getAdminAllSetting($store->created_by, $store->id);

    // Ensure reward point settings exist and prevent division by zero
    $rewardPointPrice = isset($setting['reward_point_price']) ? $setting['reward_point_price'] : 0;
    $rewardPoints = isset($setting['reward_points']) && $setting['reward_points'] != 0 ? $setting['reward_points'] : 1;

    // Calculate how much to save based on points
    $savePrice = ($customer->total_club_point * $rewardPointPrice) / $rewardPoints;

    // Final total after applying points
    $finalTotal = max(0, $request->total_price - $savePrice); // Ensure it doesn't go negative

    $input = $request->all();
    $input['total_price'] = currency_format_with_sym($finalTotal, $store->id) ?? SetNumberFormat($finalTotal);
    $input['sub_total'] = $finalTotal;
    $input['save_price'] = currency_format_with_sym($savePrice, $store->id) ?? SetNumberFormat($savePrice);
    $input['message'] = __('Points applied successfully.');

    return response()->json($input);
}


    public function index()
    {
        return view('reward-club-point::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('reward-club-point::create');
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
        $pointTransection = RewardClubPoint::find($id);
        $products = explode(',', $pointTransection->product_id);
        return view('reward-club-point::admin.clubPointOrderProducts',compact('products'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('reward-club-point::edit');
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
