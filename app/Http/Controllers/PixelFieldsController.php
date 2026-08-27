<?php

namespace App\Http\Controllers;

use App\Models\PixelFields;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class PixelFieldsController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $user = Auth::user();
        $store_settings = getStoreById(getCurrentStore());
        $pixel_plateforms = Utility::pixel_plateforms();
        return view('pixel.create',compact('store_settings','pixel_plateforms'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        session()->put(['setting_tab' => 'pixel_field_setting']);
        $store = Store::find(getCurrentStore());

        $validator = \Validator::make(
            $request->all(),
            [
                'platform'=>'required',
                'pixel_id'=>'required'
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $pixel_fields = new PixelFields();
        $pixel_fields->platform = $request->platform;
        $pixel_fields->pixel_id = $request->pixel_id;
        $pixel_fields->store_id = $store->id;
        $pixel_fields->save();

        return redirect()->back()->with('success', __('Fields Saves Successfully.!'));
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\PixelFields  $pixelFields
     * @return \Illuminate\Http\Response
     */
    public function show(PixelFields $pixelFields)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\PixelFields  $pixelFields
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $pixelFields = PixelFields::find($id);
        $pixel_plateforms = Utility::pixel_plateforms();
        return view('pixel.update',compact('pixelFields', 'pixel_plateforms'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\PixelFields  $pixelFields
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        session()->put(['setting_tab' => 'pixel_field_setting']);
        $pixelFields = PixelFields::find($id);
        $store = Store::find(getCurrentStore());
        $validator = \Validator::make(
            $request->all(),
            [
                'platform'=>'required',
                'pixel_id'=>'required'
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $pixelFields->platform = $request->platform;
        $pixelFields->pixel_id = $request->pixel_id;
        $pixelFields->store_id = $store->id;
        $pixelFields->save();

        return redirect()->back()->with('success', __('Fields Saves Successfully.!'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\PixelFields  $pixelFields
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        session()->put(['setting_tab' => 'pixel_field_setting']);
        $user = \Auth::guard()->user();
        if($user && $user->type == 'admin')
        {
            $pixelfield = PixelFields::find($id);
            if ($pixelfield) {
                $pixelfield->delete();
            }

            return redirect()->back()->with('success', __('Pixel Deleted Successfully!'));
        }
        else
        {
            return redirect()->back()->with('error',__('Permission denied'));
        }
    }
}
