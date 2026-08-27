<?php

namespace Workdo\TawktoMessenger\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Setting;

class TawktoMessengerController extends Controller
{
    public function tawkToMessengerSetting(Request $request)
    {
        session()->put(['setting_tab' => 'tawktomessenger_setting']); 
        $post['tawkto_setting_enabled'] = $request->tawkto_setting_enabled;
        $post['tawk_to_messenger'] =  $request->tawk_to_messenger;


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
            return redirect()->back()->with('success', __('Tawk.to Messenger settings updated successfully.'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function settingForm(Request $request) 
    {
		try {
		    $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
		    $html = view('tawkto-messenger::pages.tab_form', compact('setting'))->render();
		    
		    // Return JSON response
		    return response()->json([
		        'is_success' => true,
		        "msg" => __( "Tawkto Messenger form get successfully."),
		        "data" => ['content' => $html]
		    ]);
		} catch (\Exception $e) {
		    // Return JSON response
		    return response()->json([
		        'is_success' => false,
		        "msg" => __('Something went wrong!'),
		        "data" => ['content' => null]
		    ]);
		}
	} 
}
