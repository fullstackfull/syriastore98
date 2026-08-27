<?php

namespace Workdo\SignInWithOutlook\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

use League\OAuth2\Client\Provider\GenericProvider;
use Socialite;
use App\Models\Customer;
use Exception;
use App\Models\Store;
use App\Models\Setting;
use Illuminate\Support\Facades\{Auth, DB, Log, Redirect, Session,File};
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;


class SignInWithOutlookController extends Controller
{
    /**
     * Open microsoft login page
     *
     * @return void
     */
     public function outlookSettings(Request $request)
    {
        session()->put(['setting_tab' => 'outlook_signin_setting']);
        $post['outlook_setting_enabled'] = $request->outlook_setting_enabled;
        if ($request->outlook_setting_enabled == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'outlook_client_id' => 'required',
                    'outlook_client_secret' =>'required',
                    'outlook_callback' =>'required',

                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }
        $post['outlook_client_id'] = $request->outlook_client_id;
        $post['outlook_client_secret'] = $request->outlook_client_secret;
        $post['outlook_callback'] = $request->outlook_callback;

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
        return redirect()->back()->with('success', __('Outlook settings successfully updated.'));
    }
    public function outlookSignin(Request $request, $slug)
    {
        $store = getStore($slug);
        if ($store) {
            $setting = getAdminAllSetting($store->created_by, $store->id);
        } else {
            $setting = getAdminAllSetting();
        }
        config([
            'services.azure' => [
                'appId' => $setting['outlook_client_id'],
                'appSecret' => $setting['outlook_client_secret'],
                'redirectUri' => $setting['outlook_callback'],
                'authority' => 'https://login.microsoftonline.com/common',
                'authorizeEndpoint' => '/oauth2/v2.0/authorize',
                'tokenEndpoint' => '/oauth2/v2.0/token',
                'scopes' => 'openid profile offline_access user.read mailboxsettings.read calendars.readwrite contacts.readwrite',
            ]
        ]);

        $oauthClient = new GenericProvider([
            'clientId'                => config('services.azure.appId'),
            'clientSecret'            => config('services.azure.appSecret'),
            'redirectUri'             => config('services.azure.redirectUri'),
            'urlAuthorize'            => config('services.azure.authority') . config('services.azure.authorizeEndpoint'),
            'urlAccessToken'          => config('services.azure.authority') . config('services.azure.tokenEndpoint'),
            'urlResourceOwnerDetails' => '',
            'scopes'                  =>  config('services.azure.scopes')
        ]);

        $authUrl = $oauthClient->getAuthorizationUrl();
        Session::put('outlookOauthState', $oauthClient->getState());
        return redirect()->away($authUrl);
    }

    public function outlookCallback(Request $request, $slug)
    {
        $store = getStore($slug);
        if ($store) {
            $setting = getAdminAllSetting($store->created_by, $store->id);
        } else {
            $setting = getAdminAllSetting();
        }

        $tabId = $slug;
        $providedState = $request->query('state');
        if ($providedState !== Session::get('outlookOauthState')) {
            return redirect()->route('customer.login', $slug)->with('error', _('Invalid state'));
        }
        $authCode = $request->code;
        if (isset($authCode)) {
            try {
                config([
                    'services.azure' => [
                       'appId' => $setting['outlook_client_id'],
                        'appSecret' => $setting['outlook_client_secret'],
                        'redirectUri' => $setting['outlook_callback'],
                        'authority' => 'https://login.microsoftonline.com/common',
                        'authorizeEndpoint' => '/oauth2/v2.0/authorize',
                        'tokenEndpoint' => '/oauth2/v2.0/token',
                        'scopes' => 'openid profile offline_access user.read mailboxsettings.read calendars.readwrite contacts.readwrite',
                    ]
                ]);

                $oauthClient = new GenericProvider([
                    'clientId'                => config('services.azure.appId'),
                    'clientSecret'            => config('services.azure.appSecret'),
                    'redirectUri'             => config('services.azure.redirectUri'),
                    'urlAuthorize'            => config('services.azure.authority') . config('services.azure.authorizeEndpoint'),
                    'urlAccessToken'          => config('services.azure.authority') . config('services.azure.tokenEndpoint'),
                    'urlResourceOwnerDetails' => '',
                    'scopes'                  =>config('services.azure.scopes')

                ]);

                $accessToken = $oauthClient->getAccessToken('authorization_code', [
                    'code' => $authCode
                ]);
                if ($accessToken) {
                    $token = $accessToken->getToken();
                    $graph = new Graph();
                    $graph->setAccessToken($accessToken);

                    $profile = $graph->createRequest("GET", "/me")
                        ->setReturnType(Model\User::class)
                        ->execute();

                    $email = $profile->getMail();

                    $customer = Customer::where('social_id', $profile->getId())->Where('email', $email)->where('store_id', $store->id)->first();
                    if ($customer) {
                            Auth::guard('customers')->login($customer);
                            return redirect()->route('landing_page', $slug);
                    } else {
                        $user = Customer::create([
                            'first_name' => $profile->getDisplayName(),
                            'email' =>$email,
                            'social_id' => $profile->getId(),
                            'social_type' => 'microsoft',
                            'password' => bcrypt('1234'),
                            'register_date' => date('Y-m-d'),
                            'last_active' => date('Y-m-d'),
                            'store_id' => $store->id,
                        ]);
                        if (Auth::guard('customers')->attempt(['email' => $user->email, 'password' => '1234', 'store_id' => $user->store_id], $request->filled('remember'))) {
                            return redirect()->route('landing_page', $slug)->with('success', __('You have successfully logged in.'));
                        } else {
                            return redirect()->route('customer.login', $slug)->with('error', _('Something went wrong'));
                        }
                    }
                } else {
                    return redirect()->route('customer.login', $slug)->with('error', _('Failed to obtain access token'));
                }
            } catch (\League\OAuth2\Client\Provider\Exception\IdentityProviderException $e) {
                return redirect()->route('customer.login', $slug)->with('error', $e->getMessage());
            }
        } else {
            return redirect()->route('customer.login', $slug)->with('error', _('Authorization code not found'));
        }
    }


    public function settingForm(Request $request) {
		try {
		    $setting = getAdminAllSetting(auth()->user()->id, getCurrentStore());
            $callback_url = !empty($setting['outlook_callback']) ? $setting['outlook_callback'] : '';
            $store = Store::find(getCurrentStore());
            $newUrl = route('outlook.callback', $store->slug);
            if ($newUrl != $callback_url && !empty($newUrl)) {
                $callback_url = $newUrl;
            }
		    $html = view('sign-in-with-outlook::pages.tab_form', compact('setting','callback_url'))->render();
		    
		    // Return JSON response
		    return response()->json([
		        'is_success' => true,
		        "msg" => __( "Outlook signin form get successfully."),
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
