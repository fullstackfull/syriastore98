<?php

namespace Workdo\FreeShippingPopup\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class FreeShippingPopupController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('free-shipping-popup::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('free-shipping-popup::create');
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
        return view('free-shipping-popup::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('free-shipping-popup::edit');
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

    public function freeShippingPopupSettings(Request $request)
    {
        if (isset($request->free_shipping_popup_enabled) && $request->free_shipping_popup_enabled == 'on') {
            if (isset($request->free_shipping_apply_price)) {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'free_shipping_apply_price' => 'required|integer|min:1',
                    ]
                );
                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }
            }

            $post = $request->all();
            unset($post['_token']);

            $post['free_shipping_apply_price'] = $request->free_shipping_apply_price;
            $post['free_shipping_popup_enabled'] = $request->free_shipping_popup_enabled ?? 'off';
        } else {
            $post['free_shipping_popup_enabled'] = 'off';
        }
        session()->put('app_setting_tab', $request->app_setting_tab);
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

        return redirect()->back()->with('success', __('Free Shipping Pop-Up settings successfully saved.'));
    }
}
