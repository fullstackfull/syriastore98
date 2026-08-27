<?php

namespace Workdo\RecentOrders\app\Http\Controllers;

use App\Models\Setting;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class RecentOrdersController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */

    public function recentOrdersSettings(Request $request)
    {
        session()->put('app_setting_tab', $request->app_setting_tab);
        $validator = \Validator::make(
            $request->all(),
            [
                'recent_orders' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $post = $request->all();
        unset($post['_token']);

        $post['recent_orders_enabled'] = $request->recent_orders_enabled ?? 'off';

        if ($post['recent_orders_enabled'] == 'on') {

            if ($request->recent_orders >= 5) {

                $post['recent_orders'] = $request->recent_orders;
            } else {
                return redirect()->back()->with('error', 'Recent Orders must be at least 5.');
            }
        }
        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
                [
                    'name' => $key,
                    'store_id' => getCurrentStore(),
                ],
                [
                    'value' => $data,
                    'name' => $key,
                    'store_id' => getCurrentStore(),
                    'created_by' => \Auth::user()->id,
                ]
            );
        }
        return redirect()->back()->with('success', 'Recent Orders settings successfully saved.');
    }

    public function index()
    {
        return view('recent-orders::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('recent-orders::create');
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
        return view('recent-orders::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('recent-orders::edit');
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
