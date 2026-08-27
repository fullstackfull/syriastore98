<?php

namespace Workdo\QuickCheckout\app\Http\Controllers;

use App\Models\Setting;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class QuickCheckoutController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        return view('quick-checkout::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('quick-checkout::create');
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
        return view('quick-checkout::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('quick-checkout::edit');
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

    public function updateSettingButtonData(Request $request)
    {
        $post = $request->all();
        session()->put('app_setting_tab', $request->app_setting_tab);
        $checkout_option = $request->has('enable_quick_checkout') ? $request->enable_quick_checkout : '';
        $post['enable_quick_checkout'] = $checkout_option;

        $button_title = $request->has('quick_button_title') ? $request->quick_button_title : 'Buy Now';
        $post['quick_button_title'] = $button_title;

        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
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
        return redirect()->route('app-setting.index')->with('success', __('Quick Checkout setting successfully updated.'));
    }
}
