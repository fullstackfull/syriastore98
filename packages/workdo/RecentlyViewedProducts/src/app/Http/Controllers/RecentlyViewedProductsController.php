<?php

namespace Workdo\RecentlyViewedProducts\app\Http\Controllers;

use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Workdo\RecentlyViewedProducts\app\Models\RecentlyViewedProduct;
use Workdo\RecentlyViewedProducts\DataTables\RecentlyViewedProductData;
use Illuminate\Support\Facades\Cache;

class RecentlyViewedProductsController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index(RecentlyViewedProductData $dataTable)
    {
        if (!auth()->user()) {
            return redirect()->route('login');
        }
        if (auth()->user() && auth()->user()->isAbleTo('Manage Recently Viewed Product')) {
            $store = Store::find(getCurrentStore());
            $enable_recently_view_product = \App\Models\Utility::GetValueByName('enable_recently_view_product', $store->id);
            if (isset($enable_recently_view_product) && $enable_recently_view_product == 'on') {
                $products = RecentlyViewedProduct::where('store_id', getCurrentStore())->get();
                return $dataTable->render('recently-viewed-products::recently-viewed.index', compact('products'));
            } else {
                return redirect()->route('dashboard')->with('error', __('Permission denied.'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        $ids = RecentlyViewedProduct::where('store_id', getCurrentStore())->pluck('product_id')->toArray();
        $products = Product::where('store_id', getCurrentStore())->whereNotIn('id', $ids)->pluck('name', 'id')->prepend('Select Product', '');
        return view('recently-viewed-products::recently-viewed.create', compact('products'));
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Create Recently Viewed Product')) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'product_id' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }

            RecentlyViewedProduct::create([
                'product_id' => $request->product_id,
                'store_id' =>  getCurrentStore(),
            ]);

            return redirect()->back()->with('success', 'Recently viewed product successfully created.');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('recently-viewed-products::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        $recent_product = RecentlyViewedProduct::where('id', $id)->first();
        $ids = RecentlyViewedProduct::where('id', '!=', $id)->where('store_id', getCurrentStore())->pluck('product_id')->toArray();
        $products = Product::where('store_id', getCurrentStore())->whereNotIn('id', $ids)->pluck('name', 'id')->prepend('Select Product', '');
        return view('recently-viewed-products::recently-viewed.edit', compact('recent_product', 'products'));
    }

    /**
     * Update the specified resource in storage.
     * @param Request $request
     * @param int $id
     * @return Renderable
     */
    public function update(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Edit Recently Viewed Product')) {
            $query = RecentlyViewedProduct::find($id);
            $validator = \Validator::make(
                $request->all(),
                [
                    'product_id' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
            $query->product_id  = $request->product_id;
            $query->store_id  = getCurrentStore();
            $query->save();

            return redirect()->back()->with('success', 'Recently viewed product successfully updated.');
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Remove the specified resource from storage.
     * @param int $id
     * @return Renderable
     */
    public function destroy($id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Delete Recently Viewed Product')) {
            $recentlyViewedProduct = RecentlyViewedProduct::find($id);
            $recentlyViewedProduct->delete();
            return redirect()->back()->with('success', __('Recently viewed product deleted successfully.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function recentlyViewSetting(Request $request)
    {
        session()->put(['setting_tab' => 'recently_viewed_setting']);
        $post = [];
        $post['recently_viewed_time'] =  $request->recently_viewed_time;
        $post['enable_recently_view_product'] =  $request->enable_recently_view_product ?? 'off';

        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            $status = (clone $settingQuery)->updateOrCreate(
                [
                    'name' => $key,
                    'store_id' => getCurrentStore()
                ],
                [
                    'value'         => $data,
                    'name'          => $key,
                    'store_id'      => getCurrentStore(),
                    'created_by'    => auth()->user()->id,
                ]
            );
        }

        if (isset($status)) {
            return redirect()->back()->with('success', __('Recent viewed time settings updated successfully'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function settingForm(Request $request)
    {
        try {
            $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
            $html = view('recently-viewed-products::pages.tab_form', compact('setting'))->render();

            return response()->json([
                'is_success' => true,
                "msg" => __("Google signin form get successfully."),
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
