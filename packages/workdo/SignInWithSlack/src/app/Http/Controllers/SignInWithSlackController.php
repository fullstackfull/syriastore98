<?php

namespace workdo\SignInWithSlack\app\Http\Controllers;

use Exception;
use App\Models\Store;
use App\Models\Setting;
use App\Models\Customer;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class SignInWithSlackController extends Controller
{
    // store slack data
    public function saveSlackSettings(Request $request){

        session()->put('setting_tab', $request->setting_tab);
        // If sLACK setting is enabled, validate other sLACK settings
        if ($request->slack_setting_enabled == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'slack_client_id' => 'required',
                    'slack_secret_key' =>'required',
                    'slack_redirect_url' =>'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }

        $post['slack_setting_enabled']    = !empty($request->slack_setting_enabled) ? $request->slack_setting_enabled : 'off';
        $post['slack_client_id']          = !empty($request->slack_client_id) ? $request->slack_client_id : '';
        $post['slack_secret_key']         = !empty($request->slack_secret_key) ? $request->slack_secret_key : '';
        $post['slack_redirect_url']       = !empty($request->slack_redirect_url) ? $request->slack_redirect_url : '';

        // Update settings
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
        return redirect()->back()->with('success', __('Setting are updated successfully'));
    }


    // login through slack
    public function redirectToSlack($slug)
    {
        $store = getStore($slug);
        if ($store) {
            $settings = getAdminAllSetting($store->created_by, $store->id);
        } else {
            $settings = getAdminAllSetting();
        }

        config(
            [
                'services.slack.client_id' => $settings['slack_client_id'] ?? null,
                'services.slack.client_secret' => $settings['slack_secret_key'] ?? null,
                'services.slack.redirect' => $settings['slack_redirect_url'] ?? null,
            ]
        );
        return Socialite::driver('slack')->redirect();
    }

    public function handleSlackCallback(Request $request, $slug)
    {
        try {
            $store = getStore($slug);
            if ($store) {
                $settings = getAdminAllSetting($store->created_by,$store->id);
            } else {
                $settings = getAdminAllSetting();
            }

            config(
                [
                    'services.slack.client_id' => $settings['slack_client_id'] ?? null,
                    'services.slack.client_secret' => $settings['slack_secret_key'] ?? null,
                    'services.slack.redirect' => $settings['slack_redirect_url'] ?? null,
                ]
            );


            $user = Socialite::driver('slack')->user();
            $slackuser = Customer::where('email', $user->email)->first();

            if($slackuser){
                Auth::guard('customers')->login($slackuser);
                return redirect()->route('landing_page', $slug)->with('success', 'You have successfully logged in.');

            }else{
                $store = getStore($slug);
                $newUser = Customer::create([
                    'first_name' => $user->name,
                    'email' => $user->email,
                    'mobile' => $request->mobile,
                    'password' => Hash::make(1234),
                    'type' => 'customer',
                    'profile_image' => 'avatar.png',
                    'regiester_date' => date('Y-m-d'),
                    'last_active' => date('Y-m-d'),
                    'store_id' => $store->id,
                    'social_id' => $user->id,
                    'social_type' => 'slack',
                ]);
                $newUser->store_id = $store->id;
                $newUser->save();

                if (Auth::guard('customers')->attempt(['email' => $newUser->email, 'password' => 1234, 'store_id' => $newUser->store_id], $request->filled('remember'))) {
                    return redirect()->route('landing_page', $slug)->with('success', 'You have successfully logged in.');
                }
            }
        }catch (Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function settingForm(Request $request) {
		try {
		    $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());


            $callback_url = !empty($setting['slack_callback']) ? $setting['slack_callback'] : '';
            $store = Store::find(getCurrentStore());
            $newUrl = route('slack.callback', $store->slug);
            if ($newUrl != $callback_url && !empty($newUrl)) {
                $callback_url = $newUrl;
            }
		    $html = view('sign-in-with-slack::pages.tab_form', compact('setting','callback_url'))->render();

		    // Return JSON response
		    return response()->json([
		        'is_success' => true,
		        "msg" => __( "Slack sign in form get successfully."),
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
