<?php

namespace Workdo\Signinwithgithub\app\Http\Controllers;

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

class SigninwithgithubController extends Controller
{
    public function githubSigninSetting(Request $request)
    {
        session()->put('setting_tab', $request->setting_tab);
        $validator = Validator::make(
            $request->all(),
            [
                'Github_authorized_url'         => 'required',
                'Github_client_id'              => 'required',
                'Github_client_secret_key'      => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        $post['github_signin_setting_enabled'] = $request->github_signin_setting_enabled;
        $post['Github_authorized_url'] = $request->Github_authorized_url;
        $post['Github_client_id'] = $request->Github_client_id;
        $post['Github_client_secret_key'] = $request->Github_client_secret_key;

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
            return redirect()->back()->with('success', __('Github Settings are updated successfully.'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }


    public function redirectToGithub($slug)
    {
        $store = getStore($slug);
        if ($store) {
            $settings = getAdminAllSetting($store->created_by, $store->id);
        } else {
            $settings = getAdminAllSetting();
        }
        config(
            [
                'services.github.client_id' => $settings['Github_client_id'],
                'services.github.client_secret' => $settings['Github_client_secret_key'],
                'services.github.redirect' => route('github.callback', $store->slug),
            ]
        );
        return Socialite::driver('github')->redirect();
    }

    public function handleGithubCallback(Request $request, $slug)
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
                    'services.github.client_id' => $settings['Github_client_id'],
                    'services.github.client_secret' => $settings['Github_client_secret_key'],
                    'services.github.redirect' => route('github.callback', $store->slug),
                ],
            );
            $user = Socialite::driver('github')->user();
            $finduser = Customer::where('social_id', $user->id)->first();

            if ($finduser) {
                Auth::guard('customers')->login($finduser);
                return redirect()->route('landing_page', $slug)->with('success', 'You have successfully logged in.');
            } else {
                $store = getStore($slug);
                $email = $user->email;
                $newUser = Customer::create([
                    'first_name' => $user->user['name'],
                    'email' => $email,
                    'social_id' => $user->id,
                    'social_type' => 'github',
                    'password' => Hash::make(1234),
                    'mobile' => $request->mobile,
                    'type' => 'customer',
                    'profile_image' => 'avatar.png',
                    'regiester_date' => date('Y-m-d'),
                    'last_active' => date('Y-m-d'),
                    'store_id' => $store->id,
                ]);
                $newUser->store_id = $store->id;
                $newUser->save();

                if (Auth::guard('customers')->attempt(['email' => $newUser->email, 'password' => 1234, 'store_id' => $newUser->store_id], $request->filled('remember'))) {
                    return redirect()->route('landing_page', $slug)->with('success', 'You have successfully logged in.');
                }
            }
        } catch (Exception $e) {
            return redirect()->route('customer.login', $slug)->with('error', 'Login canceled Please try again.');
        }
    }
    public function settingForm(Request $request) {
		try {
		    $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());


            $callback_url = !empty($setting['github_callback']) ? $setting['github_callback'] : '';
            $store = Store::find(getCurrentStore());
            $newUrl = route('github.callback', $store->slug);
            if ($newUrl != $callback_url && !empty($newUrl)) {
                $callback_url = $newUrl;
            }
		    $html = view('signinwithgithub::pages.tab_form', compact('setting','callback_url'))->render();

		    // Return JSON response
		    return response()->json([
		        'is_success' => true,
		        "msg" => __( "Github sign in form get successfully."),
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
