<?php

namespace workdo\SignInWithBitbucket\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Socialite;
use App\Models\Customer;
use Auth;
use Exception;
use App\Models\Store;
use App\Models\Setting;

class SignInWithBitbucketController extends Controller
{
    public function BitBucketSettings(Request $request)
    {
        session()->put('setting_tab', $request->setting_tab);
        $post['bitbucket_setting_enabled'] = $request->bitbucket_setting_enabled;
        if ($request->bitbucket_setting_enabled == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'bitbucket_client_id' => 'required',
                    'bitbucket_client_secret' => 'required',
                    'bitbucket_callback' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }
        $post['bitbucket_client_id'] = $request->bitbucket_client_id;
        $post['bitbucket_client_secret'] = $request->bitbucket_client_secret;
        $post['bitbucket_callback'] = $request->bitbucket_callback;
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
        return redirect()->back()->with('success', 'BitBucket settings are updated successfully.');

    }

    public function bitbucketRedirect($slug)
    {

        $store = getStore($slug);
        if ($store) {
            $setting = getAdminAllSetting($store->created_by, $store->id);
        } else {
            $setting = getAdminAllSetting();
        }

        config([
            'services.bitbucket' => [
                'client_id' => $setting['bitbucket_client_id'],
                'client_secret' => $setting['bitbucket_client_secret'],
                'redirect' => $setting['bitbucket_callback'],
            ],
        ]);

        return Socialite::driver('bitbucket')->redirect();
    }
    public function bitbucketCallback(Request $request, $slug)
    {
        try {
            $store = getStore($slug);
            if ($store) {
                $setting = getAdminAllSetting($store->created_by, $store->id);

            } else {
                $setting = getAdminAllSetting();
            }
            config([
                'services.bitbucket' => [
                    'client_id' => $setting['bitbucket_client_id'],
                    'client_secret' => $setting['bitbucket_client_secret'],
                    'redirect' => $setting['bitbucket_callback'],
                ],
            ]);

            $user = Socialite::driver('bitbucket')->user();
            $bitbucketUser = Customer::where('social_id', $user->id)->where('store_id', $store->id)->first();
            if ($bitbucketUser) {
                Auth::guard('customers')->login($bitbucketUser);
                return redirect()->route('landing_page', $slug);

            } else {
                $user = Customer::create([
                    'first_name' => $user->name,
                    'email' => $user->email,
                    'social_id' => $user->id,
                    'social_type' => 'bitbucket',
                    'password' => '1234',
                    'regiester_date' => date('Y-m-d'),
                    'last_active' => date('Y-m-d'),
                    'store_id' => $store->id,

                ]);
                if (Auth::guard('customers')->attempt(['email' => $user->email, 'password' => 1234, 'store_id' => $user->store_id], $request->filled('remember'))) {
                    return redirect()->route('landing_page', $slug)->with('success', 'You have successfully logged in.');
                }
            }

        } catch (Exception $e) {
            return redirect()->route('customer.login', $slug)->with('error', $e->getMessage());
        }
    }

    public function settingForm(Request $request)
    {
        try {
            $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());

            $callback_url = !empty($setting['bitbucket_callback']) ? $setting['bitbucket_callback'] : '';
            $store = Store::find(getCurrentStore());
            $newUrl = route('bitbucket.callback', $store->slug);
            if ($newUrl != $callback_url && !empty($newUrl)) {
                $callback_url = $newUrl;
            }
            $html = view('sign-in-with-bitbucket::pages.tab_form', compact('setting', 'callback_url'))->render();

            // Return JSON response
            return response()->json([
                'is_success' => true,
                "msg" => __("Bitbucket sign in form get successfully."),
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
