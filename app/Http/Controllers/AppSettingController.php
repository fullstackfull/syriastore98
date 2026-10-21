<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Models\Setting;
use App\Models\Plan;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Services\WebuzoDomainService;
class AppSettingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        if (auth()->user() && auth()->user()->isAbleTo('Manage Store Setting')) {
            $user = auth()->user();
            $store = getStoreById(getCurrentStore());
            $setting = getAdminAllSetting($user->id, $store->id);
            $slug = $store->slug;
            $plan = Plan::find($user->plan_id);
            $serverIp = $subdomain_name = $subdomain_Ip = $subdomainPointing = $domainip = $domainPointing = null;
            if ($setting) {
                if (isset($setting['domains']) && $setting['domains']) {
                    $serverIp   = $_SERVER['SERVER_ADDR'];
                    $domain = $setting['domains'];
                    if (isset($domain) && !empty($domain)) {
                        $domainip = gethostbyname($domain);
                    }
                    if ($serverIp == $domainip) {
                        $domainPointing = 1;
                    } else {
                        $domainPointing = 0;
                    }
                } else {
                    $serverIp   = $_SERVER['SERVER_ADDR'];
                    $domain = $serverIp;
                    $domainip = gethostbyname($domain);
                    $domainPointing = 0;
                }
                $serverName = str_replace(
                    [
                        'http://',
                        'https://',
                    ],
                    '',
                    env('APP_URL')
                );
                $serverIp   = gethostbyname($serverName);

                if ($serverIp == $_SERVER['SERVER_ADDR']) {
                    $serverIp;
                } else {
                    $serverIp = request()->server('SERVER_ADDR');
                }

                $app_url                     = trim(env('APP_URL'), '/');

                $store_settings['store_url'] = $app_url . '/' . $slug;
                // Remove the http://, www., and slash(/) from the URL
                $input = env('APP_URL');

                // If URI is like, eg. www.way2tutorial.com/
                $input = trim($input, '/');
                // If not have http:// or https:// then prepend it
                if (!preg_match('#^http(s)?://#', $input)) {
                    $input = 'http://' . $input;
                }
                $urlParts = parse_url($input);

                $serverIp   = $_SERVER['SERVER_ADDR'];

                if (!empty($setting['subdomain']) || !empty($urlParts['host'])) {
                    $subdomain_Ip   = gethostbyname($urlParts['host']);
                    if ($serverIp == $subdomain_Ip) {
                        $subdomainPointing = 1;
                    } else {
                        $subdomainPointing = 0;
                    }
                    // Remove www.
                    $subdomain_name = preg_replace('/^www\./', '', $urlParts['host']);
                } else {
                    $subdomain_Ip = $urlParts['host'] ?? (request()->ip() ?? null);
                    $subdomainPointing = 0;
                    $subdomain_name = str_replace(
                        [
                            'http://',
                            'https://',
                        ],
                        '',
                        env('APP_URL')
                    );
                }
            }

            if (empty($serverIp)) {
                $serverIp = request()->ip();
            }
                       
            $languages = Utility::languages();
            $app_setting_tab = session()->get('app_setting_tab');
            if (empty($app_setting_tab)) {
                $app_setting_tab = 'pills-home-tab';
            }

            $invoice_logo = Utility::GetValueByName('invoice_logo', $store->id);
            $invoice_logo = get_file($invoice_logo);

            $theme_image = Utility::GetValueByName('theme_image', $store->id);
            $theme_image = get_file($theme_image);

            $metaimage = Utility::GetValueByName('metaimage', $store->id);
            $metaimage = get_file($metaimage);

            $enable_storelink = Utility::GetValueByName('enable_storelink', $store->id);
            $enable_domain = Utility::GetValueByName('enable_domain', $store->id);
            $domains = Utility::GetValueByName('domains', $store->id);
            $enable_subdomain = Utility::GetValueByName('enable_subdomain', $store->id);
            $subdomain = Utility::GetValueByName('subdomain', $store->id);
            $Additional_notes = Utility::GetValueByName('additional_notes', $store->id);
            $is_checkout_login_required = Utility::GetValueByName('is_checkout_login_required', $store->id);
            $profile = asset('themes/' . $store->theme_id . '/assets/images');

            return view('AppSetting.index', compact('setting', 'plan', 'slug', 'serverIp', 'subdomain_name', 'subdomain_Ip', 'subdomainPointing', 'domainip', 'domainPointing', 'languages', 'store', 'app_setting_tab', 'invoice_logo', 'theme_image', 'metaimage', 'enable_storelink', 'enable_domain', 'domains', 'enable_subdomain', 'subdomain', 'Additional_notes', 'is_checkout_login_required', 'profile'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        session()->put('app_setting_tab', $request->app_setting_tab);
        $json = $request->array;
        $array = $request->array;
        Cache::forget('store_' . getCurrentStore());
        $dir        = 'uploads/' . getCurrentStore();
        $new_array = [];
        foreach ($array as $key => $jsn) {
            foreach ($jsn['inner-list'] as $IN_key => $js) {
                $new_array[$jsn['section_slug']][$js['field_slug']] = $js['field_default_text'];
                if ($js['field_type'] == 'multi file upload') {
                    if (!empty($js['multi_image'])) {
                        foreach ($js['multi_image'] as $key_file => $file) {
                            $theme_image = $file;

                            $fileName = rand(10, 100) . '_' . time() . "_" . $theme_image->getClientOriginalName();
                            $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, [], $theme_image);
                            if ($upload['flag'] == '0') {
                                return redirect()->back()->with('error', $upload['msg']);
                            }
                            $img_path = '';
                            if (!empty($upload['flag']) && $upload['flag'] == 1) {
                                $img_path = $upload['image_path'];
                            }
                            $array[$key]["inner-list"][$IN_key]['image_path'][] = $img_path;
                            $array[$key][$js['field_slug']][$key_file]['image'] = $img_path;
                            $array[$key][$js['field_slug']][$key_file]['field_prev_text'] = $img_path;
                        }

                        $next_key_p_image = !empty($key_file) ? $key_file : 0;
                        if (!empty($jsn['prev_image'])) {
                            foreach ($jsn['prev_image'] as $p_key => $p_value) {
                                $next_key_p_image = $next_key_p_image + 1;
                                $array[$key][$js['field_slug']][$next_key_p_image]['image'] = $p_value;
                                $array[$key][$js['field_slug']][$next_key_p_image]['field_prev_text'] = $p_value;
                            }
                        }
                    } else {
                        if (!empty($jsn['prev_image'])) {
                            foreach ($jsn['prev_image'] as $p_key => $p_value) {
                                $array[$key][$js['field_slug']][$p_key]['image'] = $p_value;
                                $array[$key][$js['field_slug']][$p_key]['field_prev_text'] = $p_value;
                            }
                        }
                    }
                }
                if ($js['field_type'] == 'photo upload') {
                    if ($jsn['array_type'] == 'multi-inner-list') {
                        $k = 0;
                        $img_path_multi = [];
                        for ($i = 0; $i < $jsn['loop_number']; $i++) {
                            $img_path_multi[$i] = '';
                            if (empty($array[$key][$js['field_slug']][$i]['field_prev_text'])) {
                                $array[$key][$js['field_slug']][$i]['field_prev_text'] = $js['field_default_text'];
                                $img_path_multi[$i] = $js['field_default_text'];
                            } else {
                                $img_path_multi[$i] = $array[$key][$js['field_slug']][$i]['field_prev_text'];
                            }
                            if (!empty($array[$key][$js['field_slug']][$i]['image']) && gettype($array[$key][$js['field_slug']][$i]['image']) == 'object') {
                                $theme_image = $array[$key][$js['field_slug']][$i]['image'];

                                $fileName = rand(10, 100) . '_' . time() . "_" . $theme_image->getClientOriginalName();
                                $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, [], $theme_image);
                                if ($upload['flag'] == '0') {
                                    return redirect()->back()->with('error', $upload['msg']);
                                }
                                $img_path = '';
                                if (!empty($upload['flag']) && $upload['flag'] == 1) {
                                    $img_path = $upload['image_path'];
                                }
                                $array[$key][$js['field_slug']][$i]['image'] = $img_path;
                                $array[$key][$js['field_slug']][$i]['field_prev_text'] = $img_path;
                                $img_path_multi[$i] = $img_path;
                            }
                        }
                        $new_array[$jsn['section_slug']][$js['field_slug']] = $img_path_multi;
                    } else {
                        if (gettype($js['field_default_text']) == 'object') {
                            $theme_image = $js['field_default_text'];

                            $fileName = rand(10, 100) . '_' . time() . "_" . $theme_image->getClientOriginalName();
                            $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, [], $theme_image);
                            if ($upload['flag'] == '0') {
                                return redirect()->back()->with('error', $upload['msg']);
                            }
                            $img_path = '';
                            if (!empty($upload['flag']) && $upload['flag'] == 1) {
                                $img_path = $upload['image_path'];
                            }
                            $array[$key]['inner-list'][$IN_key]['field_default_text'] = $img_path;
                            $new_array[$jsn['section_slug']][$js['field_slug']] = $img_path;
                        }
                    }
                }
            }
        }
        AppSetting::updateOrInsert(
            ['page_name' => 'main', 'store_id' => getCurrentStore()], // Where condition
            ['page_name' => 'main', 'store_id' => getCurrentStore(), 'theme_json' => json_encode($array), 'theme_json_api' => json_encode($new_array)]   // Update or Insert
        );

        return redirect()->back()->with('success', __('App setting set successfully.'));
    }

    public function seoSettings(Request $request)
    {

        // P1-SEC-27. This handler writes EVERY posted name into `settings` for the caller's store. Its form is the Store Setting screen
        // (AppSettingController::index() asks for `Manage Store Setting`), so the write does too; and a payment-, mail-, storage-,
        // integration- or domain-configuration name needs the Settings permission whoever posts it (App\Services\StoreSettingsAccess).
        // A restricted value that is merely resubmitted unchanged is removed from the request rather than written again.
        if (! \App\Services\StoreSettingsAccess::permitsStoreScreen(auth()->user())
            || \App\Services\StoreSettingsAccess::refusesRestrictedPost($request, auth()->user(), getCurrentStore())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put('app_setting_tab', $request->app_setting_tab);
        $validator = \Validator::make(
            $request->all(),
            [
                'google_analytic'   => 'required',
                'fbpixel_code'      => 'required',
                'metakeyword'       => 'required',
                'metadesc'          => 'required',
            ]
        );
        $post = $request->all();
        unset($post['_token']);

        $post['google_analytic']    = $request->google_analytic;
        $post['fbpixel_code']       = $request->fbpixel_code;
        $post['metakeyword']        = $request->metakeyword;
        $post['metadesc']           = $request->metadesc;

        $dir = 'uploads/' . getCurrentStore();
        if ($request->metaimage) {
            $theme_image = $request->metaimage;
            $fileName = rand(10, 100) . '_' . time() . "_" . $request->metaimage->getClientOriginalName();
            $path = Utility::upload_file($request, 'metaimage', $fileName, $dir, []);
            if ($path['flag'] == '0') {
                return redirect()->back()->with('error', $path['msg']);
            } else {
                $where = ['name' => 'metaimage'];
                // P1-SEC-18 F6 (web twin of the admin API fix): THIS store's row. Unscoped, ->first() was the lowest-id row on the
                // platform -- some other tenant's -- so saving your own file deleted theirs and charged their size to your plan. The
                // value is data a tenant can write, so the unlink goes through StoredUpload (refuses a path outside uploads/).
                $Setting = Setting::where($where)->where('store_id', getCurrentStore())->first();

                if (!empty($Setting)) {
                    \App\Services\StoredUpload::delete($Setting->value, \Auth::user()->creatorId());
                }

                $post['metaimage'] = $path['url'];
            }
        }
        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
                [
                    'name' => $key,
                    'store_id'      => getCurrentStore()
                ],
                [
                    'value'         => $data,
                    'name'          => $key,
                    'store_id'      => getCurrentStore(),
                    'created_by'    => \Auth::user()->id,
                ]
            );
        }

        return redirect()->back()->with('success', 'Seo setting successfully saved.');
    }

    public function shippingLabelSettings(Request $request)
    {

        // P1-SEC-27. This handler writes EVERY posted name into `settings` for the caller's store. Its form is the Store Setting screen
        // (AppSettingController::index() asks for `Manage Store Setting`), so the write does too; and a payment-, mail-, storage-,
        // integration- or domain-configuration name needs the Settings permission whoever posts it (App\Services\StoreSettingsAccess).
        // A restricted value that is merely resubmitted unchanged is removed from the request rather than written again.
        if (! \App\Services\StoreSettingsAccess::permitsStoreScreen(auth()->user())
            || \App\Services\StoreSettingsAccess::refusesRestrictedPost($request, auth()->user(), getCurrentStore())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put('app_setting_tab', $request->app_setting_tab);
        $validator = \Validator::make(
            $request->all(),
            [
                'store_address'   => 'required',
                'store_city'      => 'required',
                'store_state'       => 'required',
                'store_zipcode'          => 'required',
                'store_country'          => 'required',
            ]
        );
        $post = $request->all();
        unset($post['_token']);


        $post['store_address']    = $request->store_address;
        $post['store_city']       = $request->store_city;
        $post['store_state']        = $request->store_state;
        $post['store_zipcode']           = $request->store_zipcode;
        $post['store_country']           = $request->store_country;
        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
                [
                    'name' => $key,
                    'store_id'      => getCurrentStore()
                ],
                [
                    'value'         => $data,
                    'name'          => $key,
                    'store_id'      => getCurrentStore(),
                    'created_by'    => \Auth::user()->id,
                ]
            );
        }

        return redirect()->back()->with('success', 'Shipping Label setting successfully saved.');
    }

    public function product_page_setting(Request $request)
    {
        session()->put('app_setting_tab', $request->app_setting_tab);
        
        $page_name = $request->section_name ?? ($request->page_name ?? null);
        $dir        = 'uploads/' . getCurrentStore();
        if (empty($page_name)) {
            return redirect()->back()->with('error', __('Page name not found.'));
        }
        $array = $request->array;
        if ($page_name == 'home_page_web') {
            $array = $request->array;
        }
        $decodedData = json_encode($array, true);
        $new_array = [];
        foreach ($array as $key => $jsn) {
            if (isset($jsn['inner-list']) && is_array($jsn['inner-list'])) {
                foreach ($jsn['inner-list'] as $IN_key => $js) {
                    $new_array[$jsn['section_slug']][$js['field_slug']] = $js['field_default_text'];
                    if ($js['field_type'] == 'multi file upload') {
                        if (!empty($js['multi_image'])) {
                            foreach ($js['multi_image'] as $key_file => $file) {
                                $theme_image = $file;

                                $fileName = rand(10, 100) . '_' . time() . "_" . $theme_image->getClientOriginalName();
                                $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, [], $theme_image);
                                if ($upload['flag'] == '0') {
                                    return redirect()->back()->with('error', $upload['msg']);
                                }
                                $img_path = '';
                                if (!empty($upload['flag']) && $upload['flag'] == 1) {
                                    $img_path = $upload['image_path'];
                                }
                                $array[$key][$js['field_slug']][$key_file]['image'] = $img_path;
                                $array[$key][$js['field_slug']][$key_file]['field_prev_text'] = $img_path;
                            }

                            $next_key_p_image = !empty($key_file) ? $key_file : 0;
                            if (!empty($jsn['prev_image'])) {
                                foreach ($jsn['prev_image'] as $p_key => $p_value) {
                                    $next_key_p_image = $next_key_p_image + 1;
                                    $array[$key][$js['field_slug']][$next_key_p_image]['image'] = $p_value;
                                    $array[$key][$js['field_slug']][$next_key_p_image]['field_prev_text'] = $p_value;
                                }
                            }
                        } else {
                            if (!empty($jsn['prev_image'])) {
                                foreach ($jsn['prev_image'] as $p_key => $p_value) {
                                    $array[$key][$js['field_slug']][$p_key]['image'] = $p_value;
                                    $array[$key][$js['field_slug']][$p_key]['field_prev_text'] = $p_value;
                                }
                            }
                        }
                    }
                    if ($js['field_type'] == 'photo upload') {
                        if ($jsn['array_type'] == 'multi-inner-list') {
                            $k = 0;
                            $img_path_multi = [];
                            for ($i = 0; $i < $jsn['loop_number']; $i++) {
                                $img_path_multi[$i] = '';
                                if (empty($array[$key][$js['field_slug']][$i]['field_prev_text'])) {
                                    $array[$key][$js['field_slug']][$i]['field_prev_text'] = $js['field_default_text'];
                                    $img_path_multi[$i] = $js['field_default_text'];
                                }
                                if (!empty($array[$key][$js['field_slug']][$i]['image']) && gettype($array[$key][$js['field_slug']][$i]['image']) == 'object') {
                                    $theme_image = $array[$key][$js['field_slug']][$i]['image'];

                                    $image_size = File::size($theme_image);
                                    $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
                                    if ($result == 1) {
                                        $fileName = rand(10, 100) . '_' . time() . "_" . $theme_image->getClientOriginalName();
                                        $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, [], $theme_image);
                                        if ($upload['flag'] == '0') {
                                            return redirect()->back()->with('error', $upload['msg']);
                                        }
                                        $img_path = '';
                                        if (!empty($upload['flag']) && $upload['flag'] == 1) {
                                            $img_path = $upload['image_path'];
                                        }
                                    } else {
                                        return redirect()->back()->with('error', $result);
                                    }

                                    $array[$key][$js['field_slug']][$i]['image'] = $img_path;
                                    $array[$key][$js['field_slug']][$i]['field_prev_text'] = $img_path;
                                    $img_path_multi[$i] = $img_path;
                                }
                            }
                            $new_array[$jsn['section_slug']][$js['field_slug']] = $img_path_multi;
                        } else {
                            if (gettype($js['field_default_text']) == 'object') {
                                $theme_image = $js['field_default_text'];

                                $image_size = File::size($theme_image);
                                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
                                if ($result == 1) {
                                    $fileName = rand(10, 100) . '_' . time() . "_" . $theme_image->getClientOriginalName();
                                    $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, [], $theme_image);
                                    if ($upload['flag'] == '0') {
                                        return redirect()->back()->with('error', $upload['msg']);
                                    }
                                    $img_path = '';
                                    if (!empty($upload['flag']) && $upload['flag'] == 1) {
                                        $img_path = $upload['image_path'];
                                    }
                                } else {
                                    return redirect()->back()->with('error', $result);
                                }

                                $array[$key]['inner-list'][$IN_key]['field_default_text'] = $img_path;
                                $new_array[$jsn['section_slug']][$js['field_slug']] = $img_path;
                            }
                        }
                    }
                }
            }
        }
        // Upload section inside image
        if (isset($array['section']['image']['text']) && !is_array($array['section']['image']['text']) && gettype($array['section']['image']['text']) == 'object') {
            $theme_image = $array['section']['image']['text'];
            $upload = $this->uploadThemeMedia($request, $theme_image, $dir);
            if (!$upload['error']) {
                $array['section']['image']['text'] = $upload['data']['image_path'];
                $array['section']['image']['image'] = $upload['data']['image_path'];
            } else {
                return response()->json(["error" => "Something went wrong", "data" => ["Something went wrong"]]);
            }
        }
        AppSetting::updateOrCreate(
            ['page_name' => $page_name, 'store_id' => getCurrentStore()], // Where condition
            ['page_name' => $page_name, 'store_id' => getCurrentStore(), 'theme_json' => json_encode($array), 'theme_json_api' => json_encode($new_array)]   // Update or Insert
        );

        return redirect()->back()->with('success', __(ucfirst(str_replace('_', ' ', $page_name)) . ' setting saved successfully.'));
    }

    public function ThemeSettings(Request $request)
    {

        // P1-SEC-27. This handler writes EVERY posted name into `settings` for the caller's store. Its form is the Store Setting screen
        // (AppSettingController::index() asks for `Manage Store Setting`), so the write does too; and a payment-, mail-, storage-,
        // integration- or domain-configuration name needs the Settings permission whoever posts it (App\Services\StoreSettingsAccess).
        //
        // The domain tab is the exception to "compare what was posted with what is stored": its radio posts a TOKEN under the name
        // `enable_domain` that this handler turns into three on/off flags and into hosting-panel calls (Webuzo add/delete), including
        // a DELETE of the stored custom domain whenever the token is anything but `enable_domain` -- which includes the Home and
        // Checkout tabs, whose forms post no routing field at all. So for a user who may not change routing the five routing inputs are
        // replaced by the ones that reproduce the stored state, the five names are dropped from what is written below, and the request
        // is refused outright if it tried to change them.
        $routingUser = auth()->user();
        $mayChangeRouting = \App\Services\StoreSettingsAccess::permits($routingUser);
        if (! \App\Services\StoreSettingsAccess::permitsStoreScreen($routingUser)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        if (! $mayChangeRouting) {
            $neutral = \App\Services\StoreSettingsAccess::domainRoutingNeutralised($request->all(), getCurrentStore());
            if ($neutral === null) {
                return redirect()->back()->with('error', __('Permission denied.'));
            }
            // Two independent controls keep this user away from the hosting panel: these merged inputs (the handler then derives the
            // stored state and a "switch" never happens) and the `$mayChangeRouting` condition on its three panel blocks below. Either
            // alone is enough -- which is why a mutation harness can only show them in combination -- and each covers what the other
            // could miss (a stored domain that is not in canonical form defeats the first; a future edit that changes the token
            // handling defeats the second).
            $request->merge($neutral);
        }
        if (\App\Services\StoreSettingsAccess::refusesRestrictedPost($request, $routingUser, getCurrentStore(), \App\Services\StoreSettingsAccess::DOMAIN_ROUTING)) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put('app_setting_tab', $request->app_setting_tab);
        $store = Store::find(getCurrentStore());
        $settings = Setting::where('store_id', $store->id)->pluck('value', 'name')->toArray();




$oldDomain = $settings['domains'] ?? null;
$oldCustomDomainEnabled = ($settings['enable_domain'] ?? 'off') === 'on';





        if ($request->theme_name && ($request->theme_name != $store->name)) {
            $store->name = $request->theme_name;
            $store->save();
        }
        if ($request->email && ($request->email != $store->email)) {
            $store->email = $request->email;
            $store->save();
        }

        if ($request->store_slug) {
            $validator = \Validator::make(
                $request->all(),
                [
                    'store_slug' => [
                        'required',
                        'regex:/^[a-z0-9-]+$/', // Allow only lowercase letters, numbers, and hyphens
                    ],
                ],
                [
                    'store_slug.required' => 'The store slug is required.',
                    'store_slug.regex' => 'The store slug only contains lowercase letters, numbers, and hyphens without spaces.'
                ]
            );
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->errors()->first());
            }

            $store_slug_exists = Store::where('slug', $request->store_slug)->first();
            if ($store_slug_exists && ($store_slug_exists->slug != $store->slug)) {
                return redirect()->back()->with('error', __('This store slug already exists in another store.'));
            }

            if ($request->store_slug && ($request->store_slug != $store->slug)) {
                $store->slug = $request->store_slug;
                $store->save();
            }
        }


        if ($request->default_language && ($request->default_language != $store->default_language)) {
            $store->default_language = $request->default_language;
            $store->save();
        }

        if ($request->enable_domain == 'enable_domain') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'domains' => 'required',
                ]
            );
        }
        if ($request->enable_domain == 'enable_subdomain') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'subdomain' => 'required',
                ]
            );
        }

        $user = \Auth::user();
        $post = $request->all();
        $dir = 'uploads/' . getCurrentStore();
        $settingQuery = Setting::query();

        $totalImageSize = 0;
        if ($request->hasFile('theme_logo')) {
            $totalImageSize += $request->file('theme_logo')->getSize();
        }
        if ($request->hasFile('invoice_logo')) {
            $totalImageSize += $request->file('invoice_logo')->getSize();
        }
        if ($request->hasFile('theme_favicon')) {
            $totalImageSize += $request->file('theme_favicon')->getSize();
        }
        if ($request->hasFile('theme_image')) {
            $totalImageSize += $request->file('theme_image')->getSize();
        }
        $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
        if ($result != 1) {
            $msg['flag'] = 'error';
            $msg['msg'] = $result;

            return $msg;
        }

        if ($request->theme_logo) {
            $theme_image = $request->theme_logo;
            $fileName = rand(10, 100) . '_' . time() . "_" . $request->theme_logo->getClientOriginalName();
            $path = Utility::upload_file($request, 'theme_logo', $fileName, $dir, []);

            if ($path['flag'] == '0') {
                return redirect()->back()->with('error', $path['msg']);
            } else {
                $where = ['name' => 'theme_logo'];
                // P1-SEC-18 F6 (web twin of the admin API fix): THIS store's row. Unscoped, ->first() was the lowest-id row on the
                // platform -- some other tenant's -- so saving your own file deleted theirs and charged their size to your plan. The
                // value is data a tenant can write, so the unlink goes through StoredUpload (refuses a path outside uploads/).
                $Setting = Setting::where($where)->where('store_id', getCurrentStore())->first();

                if (!empty($Setting)) {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $Setting->value);
                    \App\Services\StoredUpload::delete($Setting->value, \Auth::user()->creatorId());
                }

                $post['theme_logo'] = $path['url'] ?? null;
            }
        }
        if ($request->invoice_logo) {
            $theme_image = $request->invoice_logo;
            $fileName = rand(10, 100) . '_' . time() . "_" . $request->invoice_logo->getClientOriginalName();
            $path = Utility::upload_file($request, 'invoice_logo', $fileName, $dir, []);

            if ($path['flag'] == '0') {
                return redirect()->back()->with('error', $path['msg']);
            } else {
                $where = ['name' => 'invoice_logo'];
                // P1-SEC-18 F6 (web twin of the admin API fix): THIS store's row. Unscoped, ->first() was the lowest-id row on the
                // platform -- some other tenant's -- so saving your own file deleted theirs and charged their size to your plan. The
                // value is data a tenant can write, so the unlink goes through StoredUpload (refuses a path outside uploads/).
                $Setting = Setting::where($where)->where('store_id', getCurrentStore())->first();

                if (!empty($Setting)) {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $Setting->value);
                    \App\Services\StoredUpload::delete($Setting->value, \Auth::user()->creatorId());
                }
                $post['invoice_logo'] = $path['url'] ?? null;
            }
        }
        if ($request->theme_favicon) {
            $theme_image = $request->theme_favicon;
            $fileName = rand(10, 100) . '_' . time() . "_" . $request->theme_favicon->getClientOriginalName();
            $path = Utility::upload_file($request, 'theme_favicon', $fileName, $dir, []);


            if ($path['flag'] == '0') {
                return redirect()->back()->with('error', $path['msg']);
            } else {
                $where = ['name' => 'theme_favicon'];
                // P1-SEC-18 F6 (web twin of the admin API fix): THIS store's row. Unscoped, ->first() was the lowest-id row on the
                // platform -- some other tenant's -- so saving your own file deleted theirs and charged their size to your plan. The
                // value is data a tenant can write, so the unlink goes through StoredUpload (refuses a path outside uploads/).
                $Setting = Setting::where($where)->where('store_id', getCurrentStore())->first();

                if (!empty($Setting)) {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $Setting->value);
                    \App\Services\StoredUpload::delete($Setting->value, \Auth::user()->creatorId());
                }

                $post['theme_favicon'] = $path['url'] ?? null;
            }
        }

        if ($request->theme_image) {
            $theme_image = $request->theme_image;
            $fileName = rand(10, 100) . '_' . time() . "_" . $request->theme_image->getClientOriginalName();
            $path = Utility::upload_file($request, 'theme_image', $fileName, $dir, []);


            if ($path['flag'] == '0') {
                return redirect()->back()->with('error', $path['msg']);
            } else {
                $where = ['name' => 'theme_image'];
                // P1-SEC-18 F6 (web twin of the admin API fix): THIS store's row. Unscoped, ->first() was the lowest-id row on the
                // platform -- some other tenant's -- so saving your own file deleted theirs and charged their size to your plan. The
                // value is data a tenant can write, so the unlink goes through StoredUpload (refuses a path outside uploads/).
                $Setting = Setting::where($where)->where('store_id', getCurrentStore())->first();

                if (!empty($Setting)) {
                    Utility::changeStorageLimit(\Auth::user()->creatorId(), $Setting->value);
                    \App\Services\StoredUpload::delete($Setting->value, \Auth::user()->creatorId());
                }

                $post['theme_image'] = $path['url'] ?? null;
            }
        }

 



/*
|--------------------------------------------------------------------------
| Custom Domain
|--------------------------------------------------------------------------
*/
// P1-SEC-29: a host another store holds is refused BEFORE anything reaches the hosting panel -- including the removal of this
// store's own old custom domain, which the second block below does FIRST when the mode is switched, and which a refusal after it
// would leave done at the panel and not saved.
if ($mayChangeRouting) {
    $claimedHost = null;
    if ($request->enable_domain === 'enable_domain') {
        $claimedHost = (string) $request->domains;   // heldByAnotherStore() normalises it, as the lookups do
    } elseif ($request->enable_domain === 'enable_subdomain') {
        $appHost = parse_url('http://' . preg_replace('#^https?://#', '', trim((string) env('APP_URL'), '/')), PHP_URL_HOST);
        $claimedHost = $appHost ? strtolower(trim((string) $request->subdomain)) . '.' . preg_replace('/^www\./', '', $appHost) : null;
    }
    // A host the store ALREADY has switched on and is merely re-saving is not a claim (the model hook lets it through for the same
    // reason): without this a store that is in a duplicate from before the fix could not save the Custom tab at all.
    $alreadyServing = ($request->enable_domain === 'enable_domain' && $oldCustomDomainEnabled
            && \App\Services\HostClaims::normalise((string) $oldDomain) === \App\Services\HostClaims::normalise((string) $claimedHost))
        || ($request->enable_domain === 'enable_subdomain' && ($settings['enable_subdomain'] ?? 'off') === 'on'
            && \App\Services\HostClaims::normalise((string) ($settings['subdomain'] ?? '')) === \App\Services\HostClaims::normalise((string) $claimedHost));
    if ($claimedHost && ! $alreadyServing && \App\Services\HostClaims::heldByAnotherStore($claimedHost, $store->id)) {
        return redirect()->back()->with('error', __('This domain is already used by another store.'));
    }
}

// P1-SEC-27: the hosting panel is for a user who may change routing; for everyone else NOTHING below reaches it, whatever is posted.
if ($mayChangeRouting && $request->enable_domain === 'enable_domain') {

    $webuzo = app(WebuzoDomainService::class);

    $input = trim((string) $request->domains);

    /*
    |--------------------------------------------------------------------------
    | المستخدم ترك Custom Domain فارغ
    |--------------------------------------------------------------------------
    */
    if ($input === '') {

        if (!empty($oldDomain) && !\App\Services\HostClaims::heldByAnotherStore($oldDomain, $store->id)) {

            $deleteResult = $webuzo->deleteDomain($oldDomain);

            if (!$deleteResult['success']) {
                return redirect()->back()
                    ->with(
                        'error',
                        'Webuzo: Could not delete old domain: '
                        . $deleteResult['message']
                    );
            }
        }

        // امسح القيمة القديمة من settings
        $post['domains'] = '';

    } else {

        /*
        |--------------------------------------------------------------------------
        | تنظيف الدومين الجديد
        |--------------------------------------------------------------------------
        */

        $input = trim($input, '/');

        if (!preg_match('#^http(s)?://#', $input)) {
            $input = 'http://' . $input;
        }

        $urlParts = parse_url($input);

        if (!$urlParts || empty($urlParts['host'])) {
            return redirect()->back()
                ->with('error', 'Invalid custom domain.');
        }

        $newDomain = strtolower(
            preg_replace('/^www\./', '', $urlParts['host'])
        );

        $post['domains'] = $newDomain;

        /*
        |--------------------------------------------------------------------------
        | فقط إذا تغير الدومين
        |--------------------------------------------------------------------------
        */
        if ($oldDomain !== $newDomain) {

            /*
             * 1. أضف الجديد أولاً
             */
            $addResult = $webuzo->addDomain($newDomain, false);

            if (!$addResult['success']) {
                return redirect()->back()
                    ->with(
                        'error',
                        'Webuzo: ' . $addResult['message']
                    );
            }

            /*
             * 2. بعد نجاح الجديد، احذف القديم
             *    (P1-SEC-29: not one another store holds now -- the hosting-panel account is shared, so that would take its site down)
             */
            if (!empty($oldDomain) && !\App\Services\HostClaims::heldByAnotherStore($oldDomain, $store->id)) {

                $deleteResult = $webuzo->deleteDomain($oldDomain);

                if (!$deleteResult['success']) {
                    return redirect()->back()
                        ->with(
                            'error',
                            'New domain was added, but Webuzo could not delete old domain: '
                            . $deleteResult['message']
                        );
                }
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| المستخدم ألغى Custom Domain واختار Store Link أو Subdomain
|--------------------------------------------------------------------------
*/
if (
    $mayChangeRouting
    && $oldCustomDomainEnabled
    && $request->enable_domain !== 'enable_domain'
    && !empty($oldDomain)
    && !\App\Services\HostClaims::heldByAnotherStore($oldDomain, $store->id)   // P1-SEC-29: the panel account is shared
) {

    $webuzo = app(WebuzoDomainService::class);

    $deleteResult = $webuzo->deleteDomain($oldDomain);

    if (!$deleteResult['success']) {
        return redirect()->back()
            ->with(
                'error',
                'Webuzo: Could not delete old custom domain: '
                . $deleteResult['message']
            );
    }

    // حتى ما يضل الدومين القديم محفوظ بالداتابيز
    $post['domains'] = '';
}









if ($mayChangeRouting && $request->enable_domain === 'enable_subdomain') {

    $input = env('APP_URL');
    $input = trim($input, '/');

    if (!preg_match('#^http(s)?://#', $input)) {
        $input = 'http://' . $input;
    }

    $urlParts = parse_url($input);

    if (is_array($urlParts) && isset($urlParts['host'])) {

        $subdomain_name = preg_replace(
            '/^www\./',
            '',
            $urlParts['host']
        );

        $subdomainSlug = strtolower(
            trim((string) $request->subdomain)
        );

        $newSubdomain =
            $subdomainSlug . '.' . $subdomain_name;

        $post['subdomain'] = $newSubdomain;

        $webuzo = app(WebuzoDomainService::class);


        /*
        |--------------------------------------------------------------------------
        | هل السب دومين الجديد موجود فعلياً في Webuzo؟
        |--------------------------------------------------------------------------
        */
        $newSubdomainExists =
            $webuzo->domainExists($newSubdomain);


        /*
        |--------------------------------------------------------------------------
        | إذا غير موجود في Webuzo → أضفه
        |--------------------------------------------------------------------------
        */
     if (!$newSubdomainExists) {

    $addResult = $webuzo->addSubdomain(
        $subdomain_name,
        $subdomainSlug,
        false
    );

    if (!empty($addResult['error'])) {
        return redirect()->back()->with(
            'error',
            'Webuzo: ' . implode(' ', $addResult['error'])
        );
    }
}


        /*
        |--------------------------------------------------------------------------
        | إذا تغير اسم السب دومين → احذف القديم
        |--------------------------------------------------------------------------
        */
        if (
            !empty($oldSubdomain)
            && $oldSubdomain !== $newSubdomain
        ) {

            /*
             * نتأكد أن القديم موجود فعلياً في Webuzo
             */
            if ($webuzo->domainExists($oldSubdomain)) {

                $deleteResult =
                    $webuzo->deleteDomain($oldSubdomain);

                if (!$deleteResult['success']) {
                    return redirect()->back()->with(
                        'error',
                        'New subdomain added, but old subdomain could not be deleted: '
                        . $deleteResult['message']
                    );
                }
            }
        }

    } else {

        $post['subdomain'] = null;
    }
}


        if ($request->enable_domain) {
            $settings['enable_storelink'] = ($request->enable_domain == 'enable_storelink' || empty($request->enable_domain)) ? 'on' : 'off';
            $settings['enable_domain'] = ($request->enable_domain == 'enable_domain') ? 'on' : 'off';
            $settings['enable_subdomain'] = ($request->enable_domain == 'enable_subdomain') ? 'on' : 'off';
            $post['enable_storelink'] = $settings['enable_storelink'];
            $post['enable_domain'] = $settings['enable_domain'];
            $post['enable_subdomain'] = $settings['enable_subdomain'];
        }

        if ($request->additional_notes) {
            $additional_notes = $request->has('additional_notes') ? $request->additional_notes : 'off';
            $post['additional_notes'] = $additional_notes;
        }

        if ($request->is_checkout_login_required) {
            $is_checkout_login_required = $request->has('is_checkout_login_required') ? $request->is_checkout_login_required : 'off';
            $post['is_checkout_login_required'] = $is_checkout_login_required;
        }

        if ($request->store_address) {
            $post['store_address'] = $request->store_address;
        }

        if ($request->store_city) {
            $post['store_city'] = $request->store_city;
        }

        if ($request->store_state) {
            $post['store_state'] = $request->store_state;
        }

        if ($request->store_zipcode) {
            $post['store_zipcode'] = $request->store_zipcode;
        }

        if ($request->store_country) {
            $post['store_country'] = $request->store_country;
        }

        if ($request->coupon_list_enable) {
            $post['coupon_list_enable'] = $request->coupon_list_enable;
        }

        if ($request->enable_spam_prevent) {
            $post['enable_spam_prevent'] = $request->enable_spam_prevent;
        }

        if ($request->product_csvswich_is_enable) {
            $post['product_csvswich_is_enable'] = $request->product_csvswich_is_enable;
        }

        if ($request->products_pdf_enable) {
            $post['products_pdf_enable'] = $request->products_pdf_enable;
        }

        if ($request->cart_quantity_control_enable) {
            $post['cart_quantity_control_enable'] = $request->cart_quantity_control_enable ?? 'off';
        } else {
            $post['cart_quantity_control_enable'] = 'off';
        }
        if ($request->enable_product_compare) {
            $post['enable_product_compare'] = $request->enable_product_compare ?? 'off';
        } else {
            $post['enable_product_compare'] = 'off';
        }
        if ($request->enable_product_enquiry) {
            $post['enable_product_enquiry'] = $request->enable_product_enquiry ?? 'off';
        } else {
            $post['enable_product_enquiry'] = 'off';
        }
        if ($request->enable_product_barcode) {
            $post['enable_product_barcode'] = $request->enable_product_barcode ?? 'off';
        } else {
            $post['enable_product_barcode'] = 'off';
        }
        if ($request->enable_spin_to_win) {
            $post['enable_spin_to_win'] = $request->enable_spin_to_win ?? 'off';
        } else {
            $post['enable_spin_to_win'] = 'off';
        }
        if ($request->enable_additional_field) {
            $post['enable_additional_field'] = $request->enable_additional_field ?? 'off';
        }
        if ($request->enable_checkout_attachment) {
            $post['enable_checkout_attachment'] = $request->enable_checkout_attachment ?? 'off';
        }
        if ($request->enable_bundle_product) {
            $post['enable_bundle_product'] = $request->enable_bundle_product ?? 'off';
        }
        if ($request->enable_product_catelog) {
            $post['enable_product_catelog'] = $request->enable_product_catelog ?? 'off';
        }
        if ($request->enable_coupon_email) {
            $post['enable_coupon_email'] = $request->enable_coupon_email ?? 'off';
        }
        if ($request->enable_affiliate_product) {
            $post['enable_affiliate_product'] = $request->enable_affiliate_product ?? 'off';
        }
        if ($request->enable_auction_product) {
            $post['enable_auction_product'] = $request->enable_auction_product ?? 'off';
        }
        if ($request->enable_digital_product) {
            $post['enable_digital_product'] = $request->enable_digital_product ?? 'off';
        }
        if ($request->enable_subscribe_popup) {
            $post['enable_subscribe_popup'] = $request->enable_subscribe_popup ?? 'off';
        }
        if ($request->enable_lottery_button) {
            $post['enable_lottery_button'] = $request->enable_lottery_button ?? 'off';
        }
        if (!empty($request->theme_name) || !empty($request->email) || !empty($request->google_analytic) || !empty($request->fbpixel_code) || !empty($request->storejs) || !empty($request->storecss)) {

            // if (!isset($request->google_analytic)) {
            //     $post['google_analytic'] = !empty($request->google_analytic) ? $request->google_analytic : '';
            // }
            // if (!isset($request->fbpixel_code)) {
            //     $post['fbpixel_code'] = !empty($request->fbpixel_code) ? $request->fbpixel_code : '';
            // }
            if ($request->storejs) {
                $post['storejs'] = !empty($request->storejs) ? $request->storejs : '';
            }
            if ($request->storecss) {
                $post['storecss'] = !empty($request->storecss) ? $request->storecss : '';
            }
        }
        if ($request->product_image_zoom_is_enable) {
            $post['product_image_zoom_is_enable'] = $request->product_image_zoom_is_enable ?? 'off';
        }

        // P1-SEC-27: a user who may not change routing never writes the five routing names, whatever the handler derived for them above.
        if (! $mayChangeRouting) {
            $post = array_diff_key($post, array_flip(\App\Services\StoreSettingsAccess::DOMAIN_ROUTING));
        }

        // P1-SEC-29: the custom domain is written BEFORE the flag that switches it on, so the flag is judged against the host this request
        // saves and not against the stale one it replaces (the model hook checks every host the store has saved when a flag goes on). The
        // radio posts its token under `enable_domain`, which is the first key of the form; `enable_storelink` and `enable_subdomain` are
        // derived above and so already come last.
        $post = array_merge(array_intersect_key($post, ['domains' => true]), $post);

        $settingQuery = Setting::query();
        // P1-SEC-29: all or nothing. The Setting model refuses a host another store holds (App\Services\HostClaims); without a transaction
        // the keys written before the refused one stay saved, and the merchant is left half-applied with no message (this page prints
        // session('error'), not $errors).
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($post, $settingQuery) {
                foreach ($post as $key => $data) {
                    (clone $settingQuery)->updateOrCreate(
                        [
                            'name' => $key,
                            'store_id'      => getCurrentStore(),
                            'created_by' => auth()->user()->id
                        ],
                        [
                            'value'         => $data,
                            'name'          => $key,
                            'store_id'      => getCurrentStore(),
                            'created_by'    => auth()->user()->id,
                        ]
                    );
                }
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return redirect()->back()->with('success', __('Settings successfully updated.'));
    }

    public function FirebaseSettings(Request $request)
    {
        session()->put('app_setting_tab', $request->app_setting_tab);
        
        $firebase_enabled = !empty($request->firebase_enabled) ? $request->firebase_enabled : 'off';
        $fcm_Key = !empty($request->fcm_Key) ? $request->fcm_Key : '';

        $post['firebase_enabled'] = $firebase_enabled;
        $post['fcm_Key'] = $fcm_Key;
        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            (clone $settingQuery)->updateOrCreate(
                ['name' => $key, 'created_by' => auth()->user()->id],
                [
                    'value'         => $data,
                    'name'          => $key,
                    'store_id'      => getCurrentStore(),
                    'created_by'    => auth()->user()->id,
                ]
            );
        }

        return redirect()->back()->with('success', __('Setting successfully updated.'));
    }

    public function MobileScreenContent()
    {
        // Main page
        $path = base_path('themes/' . APP_THEME() . '/theme_json/homepage.json');
        if (!file_exists($path)) {
            return redirect()->back()->with('error', __('Theme file not found.'));
        }
        $json = json_decode(file_get_contents($path), true);

        $setting_json = AppSetting::select('theme_json')
            ->where('page_name', 'main')
            ->where('store_id', getCurrentStore())
            ->first();
        if (!empty($setting_json)) {
            $json = json_decode($setting_json->theme_json, true);
        }

        // Product Banner page
        $product_banner_json_path = base_path('theme_json/product-banner.json');
        $product_banner_json = json_decode(file_get_contents($product_banner_json_path), true);

        $setting_product_banner_json = AppSetting::select('theme_json')
            ->where('page_name', 'product_banner')
            ->where('store_id', getCurrentStore())
            ->first();
        if (!empty($setting_product_banner_json)) {
            $product_banner_json = json_decode($setting_product_banner_json->theme_json, true);
        }

        // Order Complete page
        $order_complete_json_path = base_path('theme_json/order-complete.json');
        $order_complete_json = json_decode(file_get_contents($order_complete_json_path), true);

        $setting_order_complete_json = AppSetting::select('theme_json')
            ->where('page_name', 'order_complete')
            ->where('store_id', getCurrentStore())
            ->first();
        if (!empty($setting_order_complete_json)) {
            $order_complete_json = json_decode($setting_order_complete_json->theme_json, true);
        }

        // Home pagw (WEBSITE)
        $homepage_web_json = [];
        $homepage_web_json_path = base_path('themes/' . APP_THEME() . '/theme_json/web/homepage.json');
        if (file_exists($homepage_web_json_path)) {
            $homepage_web_json = json_decode(file_get_contents($homepage_web_json_path), true);
        }

        $homepage_web_complete_json = AppSetting::select('theme_json')
            ->where('page_name', 'home_page_web')
            ->where('store_id', getCurrentStore())
            ->first();
        if (!empty($homepage_web_complete_json)) {
            $homepage_web_json = json_decode($homepage_web_complete_json->theme_json, true);
        }


        // loyality program json
        $loyality_program_json = Utility::loyality_program_json(getCurrentStore());

        $settings = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();
        $store = Store::find(getCurrentStore());
        $slug = $store->slug;
        if (empty($settings)) {
            $settings = Utility::Setting($store->id);
        }
        $themes = [];

        $user = auth()->user();
        if ($user->type == 'admin') {
            $plan = Plan::find($user->plan);
            if (!empty($plan->themes)) {
                $themes =  explode(',', $plan->themes);
            }
        }

        $app_setting_tab = session()->get('app_setting_tab');
        if (empty($app_setting_tab)) {
            $app_setting_tab = 'pills-home-tab';
        }

        $compact = ['json', 'product_banner_json', 'order_complete_json', 'homepage_web_json', 'loyality_program_json', 'slug', 'settings', 'themes', 'user', 'app_setting_tab'];
        return view('AppSetting.mobile_content', compact($compact));
    }

    public function SiteSetting(Request $request)
    {
        session()->put('app_setting_tab', $request->app_setting_tab);
        $validator = \Validator::make(
            $request->all(),
            [
                'date_format' => 'required'
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $post['date_format'] = $request->date_format;

        $settingQuery = Setting::query();
        // Iterate over the data and insert/update in the 'settings' table
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

        return redirect()->back()->with('success', __('Setting successfully updated.'));
    }

    private function getThemeJson($page_name, $default_path)
    {
        // Default data
        $json_data = json_decode(file_get_contents($default_path), true);

        // Fetch setting from database
        $setting_json_data = AppSetting::select('theme_json')
            ->where('page_name', $page_name)
            ->where('store_id', getCurrentStore())
            ->first();

        if (!empty($setting_json_data)) {
            $json_data = json_decode($setting_json_data->theme_json, true);
        }

        return $json_data;
    }

    private function uploadThemeMedia($request, $theme_image, $dir)
    {

        $image_size = File::size($theme_image);
        $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $image_size);
        if ($result == 1) {
            $fileName = rand(10, 100) . '_' . time() . "_" . $theme_image->getClientOriginalName();
            $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, [], $theme_image);
             if ($upload['flag'] == '0') {
                return redirect()->back()->with('error', $upload['msg']);
            }
            $img_path = '';
            return ["error" => false, "data" => $upload];
        } else {
            return ["error" => true, "data" => __('Plan storage limit is over so please upgrade the plan.')];
        }
    }
}