<?php

namespace Workdo\SMS\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SMSController extends Controller
{

    public function saveSmsSettings(Request $request)
    {
        session()->put(['setting_tab' => 'sms_setting']);
        session()->put(['sms_setting' => $request->sms_setting]);
        $post['sms_setting_enabled'] = $request->sms_setting_enabled;
        if (isset($request->sms_setting_enabled) && $request->sms_setting_enabled == 'on') {
            if ($request->sms_setting == "sns") {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'sns_access_key' => 'required|string|max:255',
                        'sns_secret_key' => 'required|string|max:255',
                        'sns_region' => 'required|string|max:255',
                        'sns_sender_id' => 'required|string|max:255',
                        'sns_type' => 'required|string|max:255',
                    ]
                );
            } elseif ($request->sms_setting == "twilio") {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'sms_twilio_sid' => 'required|string|max:255',
                        'sms_twilio_token' => 'required|string|max:255',
                        'sms_twilo_from_number' => 'required|string|max:255',

                    ]
                );
            } elseif ($request->sms_setting == "clockwork") {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'clockwork_api_key' => 'required|string|max:255',

                    ]
                );
            } elseif ($request->sms_setting == "melipayamak") {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'melipayamak_username' => 'required|string|max:255',
                        'melipayamak_password' => 'required|string|max:255',
                        'melipayamak_from_number' => 'required|string|max:255',

                    ]
                );
            } elseif ($request->sms_setting == "kavenegar") {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'kavenegar_apiKey' => 'required|string|max:255',
                        'kavenegar_from_number' => 'required|string|max:255',

                    ]
                );
            } else {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'smsgatewayme_apiToken' => 'required|string|max:255',
                        'Smsgatewayme_device_id' => 'required|string|max:255',
                    ]
                );
            }

            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }       
        unset($post['_token'], $post['sms']);
        $post = $request->all();

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
        return redirect()->back()->with('success', 'SMS setting successfully updated.');    
    }

    public function getSmsFields(Request $request)
    {
        $sms_setting = $request->sms_setting;
        $settings = getAdminAllSetting();

        $returnHTML = view('sms::pages.input', compact('sms_setting','settings'))->render();
        $response = [
            'is_success' => true,
            'message' => '',
            'html' => $returnHTML,
        ];
        return response()->json($response);
    }

    public function settingForm(Request $request) {
		try {
            $sms_settings=[
                "" => "Select Setting",
                "sns" => "AWS",
                "twilio" => "Twilio",
                "clockwork" => "Clockwork",
                "melipayamak" => "Melipayamak",
                "kavenegar" => "Kavenegar",
                "smsgatewayme" => "SMS Gateway Me",
        
            ];
            $settings = getAdminAllSetting(auth()->user()->id, getCurrentStore());
		    $html = view('sms::pages.tab_form', compact('settings','sms_settings'))->render();
		    
		    // Return JSON response
		    return response()->json([
		        'is_success' => true,
		        "msg" => __( "SMS setting form get successfully."),
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
