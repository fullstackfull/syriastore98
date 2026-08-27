<?php

namespace workdo\SignInWithTwitter\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;    
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Exception;
use App\Models\Store;
use App\Models\Setting;

class SignInWithTwitterController extends Controller
{
    public function TwitterSettings(Request $request)
    {
        session()->put('setting_tab', $request->setting_tab);
        $post['twitter_setting_enabled'] = $request->twitter_setting_enabled;
        if ($request->twitter_setting_enabled == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'twitter_client_id' => 'required',
                    'twitter_client_secret' => 'required',
                    'twitter_callback' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }
        // Set LinkedIn settings
        $post['twitter_client_id'] = $request->twitter_client_id;
        $post['twitter_client_secret'] = $request->twitter_client_secret;
        $post['twitter_callback'] = $request->twitter_callback;

        // Update settings
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
                    'created_by' => auth()->user()->id,
                ]
            );
        }
        return redirect()->back()->with('success', 'Twitter settings are updated successfully');

    }
    public function twitterRedirect($slug)
    {
        try {
            $store = getStore($slug);
            if ($store) {
                $setting = getAdminAllSetting($store->created_by, $store->id);
            } else {
                $setting = getAdminAllSetting();
            }
            config([
                'services.twitter-oauth-2' => [
                    'client_id' => $setting['twitter_client_id'],
                    'client_secret' => $setting['twitter_client_secret'],
                    'redirect' => $setting['twitter_callback'],
                ],
            ]);

            return Socialite::driver('twitter-oauth-2')->redirect();
        } catch (Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }
    public function twitterCallback(Request $request, $slug)
    {
        try {
            $store = getStore($slug);
            if ($store) {
                $setting = getAdminAllSetting($store->created_by, $store->id);
            } else {
                $setting = getAdminAllSetting();
            }

            config([
                'services.twitter-oauth-2' => [
                    'client_id' => $setting['twitter_client_id'],
                    'client_secret' => $setting['twitter_client_secret'],
                    'redirect' => $setting['twitter_callback'],
                ],
            ]);

            $user = Socialite::driver('twitter-oauth-2')->user();
            $twitterUser = Customer::where('social_id', $user->id)->where('store_id', $store->id)->where('social_type', 'twitter')->first();

            if ($twitterUser) {
                Auth::guard('customers')->login($twitterUser);
                return redirect()->route('landing_page', $slug);

            } else {
                $user = Customer::create([
                    'first_name' => $user->name,
                    'email' => $user->email,
                    'social_id' => $user->id,
                    'social_type' => 'twitter',
                    'password' => '1234',
                    'regiester_date' => date('Y-m-d'),
                    'last_active' => date('Y-m-d'),
                    'store_id' => $store->id,

                ]);
                if (Auth::guard('customers')->attempt(['email' => $user->email, 'password' => 1234, 'store_id' => $user->store_id], $request->filled('remember'))) {
                    return redirect()->route('landing_page', $slug)->with('success', __('You have successfully logged in.'));
                }
            }
        } catch (Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function settingForm(Request $request)
    {
        try {
            $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());

            $callback_url = !empty($setting['twitter_callback']) ? $setting['twitter_callback'] : '';
            $store = Store::find(getCurrentStore());
            $newUrl = route('twitter.callback', $store->slug);
            if ($newUrl != $callback_url && !empty($newUrl)) {
                $callback_url = $newUrl;
            }
            $html = view('sign-in-with-twitter::pages.tab_form', compact('setting', 'callback_url'))->render();

            // Return JSON response
            return response()->json([
                'is_success' => true,
                "msg" => __("Twitter sign in form get successfully."),
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
