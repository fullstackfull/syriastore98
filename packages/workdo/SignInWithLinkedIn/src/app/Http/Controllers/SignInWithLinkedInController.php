<?php

namespace Workdo\SignInWithLinkedIn\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Socialite;
use App\Models\Customer;
use Auth;
use Exception;
use App\Models\Store;
use App\Models\Setting;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Cache;

class SignInWithLinkedInController extends Controller
{
    public function LinkedinSettings(Request $request)
    {
        session()->put(['setting_tab' => 'linkedin_signin_setting']);
        $post['linkedin_setting_enabled'] = $request->linkedin_setting_enabled;

        if ($request->linkedin_setting_enabled == 'on' && !empty($request->linkedin_sign_in_image)) {
            $linkedin_sign_in_image = upload_theme_image($request->linkedin_sign_in_image);
            if ($linkedin_sign_in_image['status'] == false) {
                return redirect()->back()->with('error', $linkedin_sign_in_image['message']);
            } else {
                $where = ['name' => 'linkedin_sign_in_image'];
                $Setting = Setting::where($where)->first();

                if (!empty($Setting)) {
                    if (File::exists(base_path($Setting->value))) {
                        File::delete(base_path($Setting->value));
                    }
                }

                $post['linkedin_sign_in_image'] = $linkedin_sign_in_image['image_path'];
            }
        }
        if ($request->linkedin_setting_enabled == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'linkedin_client_id' => 'required',
                    'linkedin_client_secret' =>'required',
                    'linkedin_callback' =>'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }
        $post['linkedin_client_id'] = $request->linkedin_client_id;
        $post['linkedin_client_secret'] = $request->linkedin_client_secret;
        $post['linkedin_callback'] = $request->linkedin_callback;

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
        return redirect()->back()->with('success', __('LinkedIn settings successfully updated.'));
    }

    public function linkedinRedirect($slug)
    {
        $store = getStore($slug);
        if ($store) {
            $setting = getAdminAllSetting($store->created_by,$store->id);
        } else {
            $setting = getAdminAllSetting();
        }

            config([
                'services.linkedin-openid' => [
                    'client_id' => $setting['linkedin_client_id'],
                    'client_secret' => $setting['linkedin_client_secret'],
                    'redirect' => $setting['linkedin_callback'],
                ],
            ]);

        return Socialite::driver('linkedin-openid')->redirect();
    }

    public function linkedinCallback(Request $request,$slug)
    {
        try {
            $store = getStore($slug);
            if ($store) {
                $setting = getAdminAllSetting($store->created_by,$store->id);
            } else {
                $setting = getAdminAllSetting();
            }

            config([
                'services.linkedin-openid' => [
                    'client_id' => $setting['linkedin_client_id'],
                    'client_secret' => $setting['linkedin_client_secret'],
                    'redirect' => $setting['linkedin_callback'],
                ],
            ]);

            $user = Socialite::driver('linkedin-openid')->user();
            $linkedinUser = Customer::where('social_id', $user->id)->where('store_id', $store->id)->first();
            if ($linkedinUser) {
                Auth::guard('customers')->login($linkedinUser);
                return redirect()->route('landing_page', $slug);

            } else {
                $user = Customer::create([
                    'first_name' => $user->name,
                    'email' => $user->email,
                    'social_id' => $user->id,
                    'social_type' => 'linkedin',
                    'password' =>'1234',
                    'regiester_date'=> date('Y-m-d'),
                    'last_active' => date('Y-m-d'),
                    'store_id' =>$store->id,

                ]);

                if (Auth::guard('customers')->attempt(['email' => $user->email, 'password' => 1234, 'store_id' => $user->store_id], $request->filled('remember'))) {
                    return redirect()->route('landing_page', $slug)->with('success', __( 'You have successfully logged in.'));
                } else {
                    return redirect()->route('customer.login', $slug)->with('error', __('Something went wrong!'));
                }
            }
        } catch (Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function settingForm(Request $request) {
		try {
		    $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
            $callback_url = !empty($setting['linkedin_callback']) ? $setting['linkedin_callback'] : '';

            $store = Store::find(getCurrentStore());
                $newUrl = route('linkedin.callback', $store->slug);
                if ($newUrl != $callback_url && !empty($newUrl)) {
                    $callback_url = $newUrl;
                }
		    $html = view('sign-in-with-linked-in::pages.tab_form', compact('setting','callback_url'))->render();

		    // Return JSON response
		    return response()->json([
		        'is_success' => true,
		        "msg" => __( "Linkedin signin form get successfully."),
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
