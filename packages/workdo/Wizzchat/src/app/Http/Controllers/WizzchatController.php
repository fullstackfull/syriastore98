<?php

namespace Workdo\Wizzchat\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Models\Setting;

class WizzchatController extends Controller
{
    public function wizzchatSetting(Request $request)
    {

        session()->put(['setting_tab' => 'wizzchat_setting']);
        $post['is_wizzchat_enabled'] = $request->is_wizzchat_enabled;
        $post['wizzchat_messenger'] =  $request->wizzchat_messenger;

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
            return redirect()->back()->with('success', __('Wizzchat settings updated successfully.'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function settingForm(Request $request) {
		try {
		    $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
		    $html = view('wizzchat::pages.tab_form', compact('setting'))->render();
		    
		    // Return JSON response
		    return response()->json([
		        'is_success' => true,
		        "msg" => __( "wizzchat form get successfully."),
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
