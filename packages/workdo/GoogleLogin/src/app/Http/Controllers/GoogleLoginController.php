<?php

namespace Workdo\GoogleLogin\app\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Store;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Socialite\Facades\Socialite;

class GoogleLoginController extends Controller
{
    public function googleLoginSetting(Request $request)
    {
        session()->put(['setting_tab' => 'google_signin_setting']);
        $validator = Validator::make(
            $request->all(),
            [
                'Google_authorized_url'         => 'required',
                'Google_client_id'              => 'required',
                'Google_client_secret_key'      => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $post['google_login_setting_enabled'] = $request->google_login_setting_enabled;
        $post['Google_authorized_url'] = $request->Google_authorized_url;
        $post['Google_client_id'] = $request->Google_client_id;
        $post['Google_client_secret_key'] = $request->Google_client_secret_key;

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
            return redirect()->back()->with('success', __('Google Login settings updated successfully.'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function redirectToGoogle($slug)
    {
        $store = getStore($slug);
        if ($store) {
            $settings = getAdminAllSetting($store->created_by, $store->id);
        } else {
            $settings = getAdminAllSetting();
        }

        if (empty($settings['Google_client_id']) || empty($settings['Google_client_secret_key']) || empty($settings['Google_authorized_url'])) {
            return redirect()->route('customer.login', $slug)->with('error', __('Google login is not configured properly.'));
        }

        config(
            [
                'services.google.client_id' => $settings['Google_client_id'],
                'services.google.client_secret' => $settings['Google_client_secret_key'],
                'services.google.redirect' => route('google.callback', $store->slug),
            ]
        );

        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(Request $request, $slug)
    {
        try {
            $store = getStore($slug);
            if ($store) {
                $settings = getAdminAllSetting($store->created_by, $store->id);
            } else {
                $settings = getAdminAllSetting();
            }
            config(
                [
                    'services.google.client_id' => $settings['Google_client_id'],
                    'services.google.client_secret' => $settings['Google_client_secret_key'],
                    'services.google.redirect' => route('google.callback', $store->slug),
                ]
            );
            $user = Socialite::driver('google')->user();

            $finduser = Customer::where('social_id', $user->id)->first();

            if ($finduser) {
                Auth::guard('customers')->login($finduser);
                return redirect()->route('landing_page', $slug)->with('success', __('You have successfully logged in.'));

            } else {
                $store = getStore($slug);
                $newUser = Customer::create([
                    'first_name' => $user->user['given_name'],
                    'last_name' => $user->user['family_name'],
                    'email' => $user->email,
                    'social_id' => $user->id,
                    'social_type' => 'google',
                    'mobile' => $request->mobile,
                    'password' => Hash::make(1234),
                    'type' => 'customer',
                    'profile_image' => 'avatar.png',
                    'regiester_date' => date('Y-m-d'),
                    'last_active' => date('Y-m-d'),
                    'store_id' => $store->id,
                ]);
                $newUser->store_id = $store->id;
                $newUser->save();

                if (Auth::guard('customers')->attempt(['email' => $newUser->email, 'password' => 1234, 'store_id' => $newUser->store_id], $request->filled('remember'))) {
                    return redirect()->route('landing_page', $slug)->with('success', __('You have successfully logged in.'));
                }
            }
        } catch (Exception $e) {
            return redirect()->route('customer.login', $slug)->with('error', __('Login canceled Please try again.'));
        }
    }

    public function settingForm(Request $request) {
        try {
            $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
            $callback_url = !empty($setting['Google_authorized_url']) ? $setting['Google_authorized_url'] : '';
            $store = Store::find(getCurrentStore());
            $newUrl = route('google.callback', $store->slug);
            if ($newUrl != $callback_url && !empty($newUrl)) {
                $callback_url = $newUrl;
            }
            $html = view('google-login::pages.tab_form', compact('setting','callback_url'))->render();
            
            // Return JSON response
            return response()->json([
                'is_success' => true,
                "msg" => __( "Google signin form get successfully."),
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
