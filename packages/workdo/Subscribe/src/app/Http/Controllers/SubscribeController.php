<?php

namespace Workdo\Subscribe\app\Http\Controllers;

use Illuminate\Contracts\Support\Renderable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SubscribeController extends Controller
{
    /**
     * Display a listing of the resource.
     * @return Renderable
     */
    public function index()
    {
        //return view('subscribe::index');
    }

    /**
     * Show the form for creating a new resource.
     * @return Renderable
     */
    public function create()
    {
        return view('subscribe::create');
    }

    /**
     * Store a newly created resource in storage.
     * @param Request $request
     * @return Renderable
     */
    public function store(Request $request)
    {
        $validate = \Validator::make(
            $request->all(),
            [
                'email' => [
                    'required',
                    'string',
                    'email',
                    'max:255',
                ],
            ]
        );
        if ($validate->fails()) {
            $messages = $validate->getMessageBag();
            return response()->json([
                'success' => false,
                'message' =>  $messages->first()
            ]);
        }

        $store = \App\Models\Store::find($request->store_id);
        $customer = \App\Models\Customer::where('email', $request->email)->first();
        $newsletter = new \App\Models\Newsletter();
        $newsletter->email          = $request->email;
        $newsletter->customer_id    = isset($customer) ? $customer->id : 0;
        $newsletter->store_id       = $store->id;
        $newsletter->save();

        return response()->json([
            'success' => true,
            'message' =>  __('Subscribe newsletter successfully!')
        ]);
    }

    /**
     * Show the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function show($id)
    {
        return view('subscribe::show');
    }

    /**
     * Show the form for editing the specified resource.
     * @param int $id
     * @return Renderable
     */
    public function edit($id)
    {
        return view('subscribe::edit');
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
