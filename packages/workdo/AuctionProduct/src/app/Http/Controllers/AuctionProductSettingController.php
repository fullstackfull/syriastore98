<?php

namespace Workdo\AuctionProduct\app\Http\Controllers;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\AuctionProduct\app\Models\AuctionProductSetting;
use Workdo\AuctionProduct\DataTables\AuctionProductSettingData;
use Illuminate\Support\Facades\Cache;

class AuctionProductSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(AuctionProductSettingData $dataTable)
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }
        $store = Store::find(getCurrentStore());
        $enable_auction_product = \App\Models\Utility::GetValueByName('enable_auction_product', $store->id);
        if (isset($enable_auction_product) && $enable_auction_product == 'on') {
            $auction_setting = AuctionProductSetting::where('store_id', getCurrentStore())->get();
            return $dataTable->render('auction-product::auctionsetting.index', compact('auction_setting'));
        } else {
            return redirect()->route('dashboard')->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        $ids = AuctionProductSetting::where('store_id', getCurrentStore())
            ->pluck('auction_product')
            ->flatMap(function ($ids) {
                return explode(',', $ids);
            })
            ->toArray();
        $product = Product::where('store_id', getCurrentStore())
            ->where('product_type', 'auction')
            ->whereNotIn('id', $ids)
            ->pluck('name', 'id');

        return view('auction-product::auctionsetting.create', compact('product'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        $auction_setting = new AuctionProductSetting();
        $auction_setting->name = $request->name;
        $auction_setting->start_date = $request->start_date;
        $auction_setting->auction_product = implode(',', !empty($request->auction_product) ? (array) $request->auction_product : []);
        $auction_setting->end_date = $request->end_date;
        $auction_setting->start_time = $request->start_time;
        $auction_setting->end_time = $request->end_time;
        $auction_setting->created_by = \Auth::user()->id;
        $auction_setting->store_id = getCurrentStore();

        $auction_setting->save();

        return redirect()->back()->with('success', __('Setting successfully created.'));
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('auction-product::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $auction_setting = AuctionProductSetting::find($id);
        if ($auction_setting) {
            $ids = AuctionProductSetting::where('id', '!=', $id)->where('store_id', getCurrentStore())->pluck('auction_product')->flatMap(function ($ids) {
                return explode(',', $ids);
            })->toArray();
            $product = Product::where('store_id', getCurrentStore())->where('product_type', 'auction')->whereNotIn('id', $ids)->pluck('name', 'id');
            $auction_product = explode(',', $auction_setting->auction_product);

            return view('auction-product::auctionsetting.edit', compact('auction_setting', 'product', 'auction_product'));
        } else {
            return redirect()->back()->with('error', __('Something went wrong.'));
        }

    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        $setting = AuctionProductSetting::find($id);
        if ($setting) {
            $setting->name = $request->name;
            $setting->auction_product = implode(',', !empty($request->auction_product) ? (array) $request->auction_product : []);
            $setting->start_date = $request->start_date;
            $setting->end_date = $request->end_date;
            $setting->start_time = $request->start_time;
            $setting->end_time = $request->end_time;
            $setting->created_by = \Auth::user()->id;
            $setting->store_id = getCurrentStore();
            $setting->save();

            return redirect()->back()->with('success', __('Setting successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Something went wrong.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        $setting = AuctionProductSetting::find($id);
        if ($setting) {
            $setting->delete();

            return redirect()->back()->with('success', __('Setting delete successfully.'));
        } else {
            return redirect()->back()->with('error', __('Something went wrong.'));
        }
    }

    public function updateStatus(Request $request)
    {
        $auctionId = $request->input('auctionId');
        $isActivated = $request->input('isActivated');
        $auction = AuctionProductSetting::findOrFail($auctionId);
        if ($isActivated == 'true') {
            $auction->is_active = 1;
        } else {
            $auction->is_active = 0;
        }
        $auction->save();
    }
}
