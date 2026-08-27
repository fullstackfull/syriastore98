<?php

namespace Workdo\PurchaseNotification\app\Http\Controllers;

use App\Models\Setting;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PurchaseNotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */

    public function purchaseNotificationSettings(Request $request)
    {
        session()->put('app_setting_tab', $request->app_setting_tab ?? 'pills-purchase_notification-tab');
        $validator = \Validator::make(
            $request->all(),
            [
                'next_time_display'         => 'required',
                'display_time'              => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $post = $request->all();
        unset($post['_token']);

        if (!empty($request->product_id)) {
            $post['product_id'] = implode(',', $request->product_id);
        } else {
            $post['product_id'] = '';
        }
        $post['next_time_display']              = $request->next_time_display;
        $post['display_time']                   = $request->display_time;
        $post['purchase_notification_enabled']  = $request->purchase_notification_enabled ?? 'off';
        $post['open_product_page']              = $request->open_product_page ?? 'off';
        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
                [
                    'name'          => $key,
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
        return redirect()->back()->with('success', __('Purchase notification settings successfully saved.'));
    }

    public function index()
    {
        return view('purchase-notification::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('purchase-notification::create');
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
        return view('purchase-notification::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('purchase-notification::edit');
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
}
