<?php

namespace workdo\SignInWithFacebook\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Socialite;
use App\Models\Customer;
use Auth;
use Exception;
use App\Models\Store;
use App\Models\Setting;
use Illuminate\Support\Facades\File;

class SignInWithFacebookController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('signinwithfacebook::index');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('signinwithfacebook::create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        //
    }

    /**
     * Show the specified resource.
     */
    public function show($id)
    {
        return view('signinwithfacebook::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('signinwithfacebook::edit');
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id): RedirectResponse
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        //
    }

    public function facebookSettings(Request $request)
    {
        session()->put(['setting_tab' => 'facebook_signin_setting']);
       
        $post['facebook_setting_enabled'] = $request->facebook_setting_enabled;
        if ($request->facebook_setting_enabled == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'facebook_client_id' => 'required',
                    'facebook_client_secret' =>'required',
                    'facebook_callback' =>'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }

        if ($request->facebook_setting_enabled == 'on' && !empty($request->facebook_sign_in_image)) {
            $facebook_sign_in_image = upload_theme_image( $request->facebook_sign_in_image);
            if ($facebook_sign_in_image['status'] == false) {
                return redirect()->back()->with('error', $facebook_sign_in_image['message']);
            } else {
                $where = ['name' => 'facebook_sign_in_image'];
                $Setting = Setting::where($where)->first();

                if (!empty($Setting)) {
                    if (File::exists(base_path($Setting->value))) {
                        File::delete(base_path($Setting->value));
                    }
                }

                $post['facebook_sign_in_image'] = $facebook_sign_in_image['image_path'];
            }
        }

        $post['facebook_client_id'] = $request->facebook_client_id;
        $post['facebook_client_secret'] = $request->facebook_client_secret;
        $post['facebook_callback'] = $request->facebook_callback;
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
        return redirect()->back()->with('success', __('Facebook setting successfully updated.'));
    }

    public function facebookRedirect($slug)
    {
        $store = getStore($slug);
        if ($store) {
            $setting = getAdminAllSetting($store->created_by,$store->id);
        } else {
            $setting = getAdminAllSetting();
        }

            config([
                'services.facebook' => [
                    'client_id' => $setting['facebook_client_id'] ?? '',
                    'client_secret' => $setting['facebook_client_secret'] ?? '',
                    'redirect' => $setting['facebook_callback'] ?? '',
                ],
            ]);

        return Socialite::driver('facebook')->redirect();
    }

    public function facebookCallback(Request $request,$slug)
    {
        try {
            $store = getStore($slug);
            if ($store) {
                $setting = getAdminAllSetting($store->created_by,$store->id);
            } else {
                $setting = getAdminAllSetting();
            }

            config([
                'services.facebook' => [
                    'client_id' => $setting['facebook_client_id'] ?? '',
                    'client_secret' => $setting['facebook_client_secret'] ?? '',
                    'redirect' => $setting['facebook_callback'] ?? '',
                ],
            ]);

            $user = Socialite::driver('facebook')->user();
            $facebookUser = Customer::where('social_id', $user->id)->where('store_id', $store->id)->first();
            if ($facebookUser) {
                Auth::guard('customers')->login($facebookUser);
                return redirect()->route('landing_page', $slug);

            } else {
                $user = Customer::create([
                    'first_name' => $user->name,
                    'email' => $user->email,
                    'social_id' => $user->id,
                    'social_type' => 'facebook',
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
            return redirect()->route('customer.login', $slug)->with('error', $e->getMessage());
        }
    }
    public function settingForm(Request $request) {
		try {
		    $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());

            $callback_url = !empty($setting['facebook_callback']) ? $setting['facebook_callback'] : '';
            $store = Store::find(getCurrentStore());
            $newUrl = route('facebook.callback', $store->slug);
            if ($newUrl != $callback_url && !empty($newUrl)) {
                $callback_url = $newUrl;
            }
		    $html = view('sign-in-with-facebook::pages.tab_form', compact('setting','callback_url'))->render();

		    // Return JSON response
		    return response()->json([
		        'is_success' => true,
		        "msg" => __( "Facebook signin form get successfully."),
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
