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
use App\Models\User;
use App\Policies\UserPolicy;
use App\Services\StoredUpload;
use App\Services\WebuzoDomainService;
class AppSettingController extends Controller
{
    /**
     * The feature-flag checkboxes that `@stack('appSettingEnableBtn')` renders, and the panel whose
     * form renders it. GAP-289: see the loop in ThemeSettings() for why an absent field may only be
     * written as `'off'` when the save came from that panel.
     *
     * PUBLIC so a test can read them instead of repeating them -- the same reason
     * `StoreSettingsAccess::DOMAIN_ROUTING` is public. `StoreSettingPanelCrossWriteTest` also pins
     * the marker against `AppSetting/index.blade.php`, because a string shared between a Blade file
     * and a controller drifts silently otherwise.
     *
     * @var list<string>
     */
    public const PANEL_OWNED_FEATURE_FLAGS = [
        'cart_quantity_control_enable',
        'enable_product_compare',
        'enable_product_enquiry',
        'enable_product_barcode',
        'enable_spin_to_win',
    ];

    /** The `app_setting_tab` marker of the form that carries those checkboxes. */
    public const FEATURE_FLAG_PANEL = 'pills-home-tab';

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

            /* P2.5-WAVE-23. RESOLVED ONCE, AND NOT THROUGH `$_SERVER[...]` DIRECTLY.
             *
             * MEASURED: /app-setting returned HTTP 500 with `Undefined array key "SERVER_ADDR"`.
             * The key is set by Apache and by nginx + PHP-FPM, so this is not a crash every
             * deployment would see -- but it is absent under the PHP built-in server and under
             * the CLI SAPI, which means this screen cannot be opened in development and any
             * artisan command or queued job reaching this path fatals.
             *
             * `request()->server('SERVER_ADDR')` reads the SAME `$_SERVER` bag, so wherever the
             * key exists the value is byte-identical and nothing changes. Where it does not, it
             * returns null instead of raising -- and this method ALREADY handles a null address:
             * `if (empty($serverIp)) { $serverIp = request()->ip(); }` a few dozen lines below.
             * The accessor is not a new idea here either; line 61 of this same method used it.
             *
             * The comparisons below behave correctly against null: in PHP 8 a non-empty string
             * is never == null, so an unresolved address takes the "not pointing" branch, which
             * is the honest answer when the server's own address is unknown.
             */
            $serverAddr = request()->server('SERVER_ADDR');
            if ($setting) {
                if (isset($setting['domains']) && $setting['domains']) {
                    $serverIp   = $serverAddr;
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
                    $serverIp   = $serverAddr;
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

                if ($serverIp == $serverAddr) {
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

                $serverIp   = $serverAddr;

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
                            $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, \App\Services\PlatformUploadPolicy::imageRules(['svg']), $theme_image);
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
                                $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, \App\Services\PlatformUploadPolicy::imageRules(['svg']), $theme_image);
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
                            $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, \App\Services\PlatformUploadPolicy::imageRules(['svg']), $theme_image);
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
                                $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, \App\Services\PlatformUploadPolicy::imageRules(['svg']), $theme_image);
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
                                        $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, \App\Services\PlatformUploadPolicy::imageRules(['svg']), $theme_image);
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
                                    $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, \App\Services\PlatformUploadPolicy::imageRules(['svg']), $theme_image);
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
                // P2.5 area-wide-no-withinput-witherrors (CLASS A). The toast stays; the bag
                // and the old input are added so the @error('store_slug') block already
                // written into resources/views/AppSetting/index.blade.php:161 stops being
                // dead markup. Never bare ->withInput(): see
                // tests/Feature/Security/CredentialSinksTest.php:260.
                return redirect()->back()
                    ->with('error', $validator->errors()->first())
                    ->withErrors($validator)
                    ->withInput($request->except(['_token', 'password', 'password_confirmation']));
            }

            /* PHASE 03 M5/M6. A SECOND WRITER OF `stores.slug`, FOUND BY AN INDEPENDENT REVIEW.
               `User::slugs()` reserves `platform` on the MINT path, and a comment there used to
               claim it was the only place a store slug is set. It is not: this line assigns one
               straight from request input, and so does the Flutter store-settings endpoint. A
               merchant who renamed their store to `platform` would be served at the platform's own
               namespace -- and `routes/customer.php` declares `{storeSlug?}/login` and
               `{storeSlug?}/register`, so their CUSTOMER sign-in and registration forms would
               answer at `/platform/login` and `/platform/register`, which a visitor reads as ours.
               Refused rather than suffixed, because a merchant typing a name should be told. */
            if (Store::slugIsReserved($request->store_slug)) {
                return redirect()->back()
                    ->with('error', __('This store slug is reserved by the platform. Please choose another.'))
                    ->withInput($request->except(['_token', 'password', 'password_confirmation']));
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
// P1-PLT-06. ONLY WHEN THE POST NAMES ANOTHER MODE. The Home and Checkout tabs post no routing field at all (and the Custom tab posts none on a
// plan that allows neither domain nor sub-domain), so `enable_domain` is ABSENT there, and absent is not "the user cancelled the custom domain":
// it used to delete the store's own domain at the hosting panel and blank `domains` on a rename or a checkout toggle. `is_string` is the
// whole test: absent and an empty string are both null by the time they get here (ConvertEmptyStringsToNull), and the mode flags a few lines
// down are only rewritten `if ($request->enable_domain)`, so this branch now agrees with that.
if (
    $mayChangeRouting
    && $oldCustomDomainEnabled
    && is_string($request->enable_domain)
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

        /*
         * GAP-289 -- AN `else` THAT WRITES 'off' BELONGS TO THE PANEL THAT OWNS THE CHECKBOX, AND TO
         * NO OTHER PANEL.
         *
         * These five were the only names in this handler whose absence from the request was written
         * as `'off'` rather than left alone -- the ~30 others are all guarded with `if ($request->x)`.
         * And THREE forms post to this one handler: `GET /app-setting` renders `pills-home-tab`,
         * `pills-custom-tab` (storecss/storejs) and `pills-checkout-tab`, while the checkboxes for
         * these five are rendered by `@stack('appSettingEnableBtn')` inside the HOME form only (five
         * vendored modules push into that stack from their own ViewComposers).
         *
         * MEASURED before this change: saving the Style tab, or the Checkout tab, wrote `'off'` to
         * ALL FIVE -- a merchant pasting CSS silently lost product compare, product enquiry, product
         * barcode, spin-to-win and cart quantity control, with a success message and nothing to say
         * anything else had changed. `StoreSettingPanelCrossWriteTest` drives both panels.
         *
         * WHY THE `else` IS NOT SIMPLY DELETED: an unchecked HTML checkbox posts nothing, so for the
         * owning panel this is the ONLY way the merchant turns one of these off. Deleting it would
         * remove the off switch, which `test_control_the_owning_panel_can_still_switch_a_feature_flag_off`
         * fails on. The request cannot tell "unticked here" from "a different form was saved" --
         * that information is absent from it -- so the panel marker every one of these forms already
         * posts is what separates them.
         *
         * THE SAME HAZARD, THE SAME SHAPE AS THE FIX ABOVE. This handler already neutralises the
         * domain-routing family for exactly this reason, and its docblock says so: a save "includes
         * the Home and Checkout tabs, whose forms post no routing field at all"
         * (`StoreSettingsAccess::DOMAIN_ROUTING`). These five are that hazard, left standing.
         *
         * A REQUEST THAT NAMES NO PANEL LEAVES THEM ALONE, deliberately: silent data loss is the
         * failure being fixed, so the absent-marker case fails towards preserving what is stored. A
         * flag can still be switched off from its own panel, which is where its control is.
         */
        foreach (self::PANEL_OWNED_FEATURE_FLAGS as $featureFlag) {
            if ($request->input($featureFlag)) {
                $post[$featureFlag] = $request->input($featureFlag);
            } elseif ($request->input('app_setting_tab') === self::FEATURE_FLAG_PANEL) {
                $post[$featureFlag] = 'off';
            }
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

        // P1-SEC-29: all or nothing. The Setting model refuses a host another store holds (App\Services\HostClaims); without a transaction
        // the keys written before the refused one stay saved, and the merchant is left half-applied with no message (this page prints
        // session('error'), not $errors).
        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($post) {
                foreach ($post as $key => $data) {
                    // P1-PLT-07: addressed by (name, store) -- NOT by the saving user. With `created_by` in the match key a staff
                    // member's save never found the owner's row and INSERTED a second one for the same name in the same store;
                    // `settings` has no unique index, so both survived and every pluck() reader took whichever came last. Who the row
                    // belongs to is settled by App\Services\SettingOwnership on the way through the model. putForStore() rather than
                    // updateOrCreate() because on a store that ALREADY carries duplicates the two disagree about which row is live --
                    // see its docblock, where the measurement is.
                    Setting::putForStore($key, $data, getCurrentStore(), auth()->user()->id);
                }
            });
        } catch (\Illuminate\Validation\ValidationException $e) {
            return redirect()->back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return redirect()->back()->with('success', __('Settings successfully updated.'));
    }

    public function FirebaseSettings(Request $request)
    {
        // P1-SEC-30: a fixed-name credential writer. The menu hides this tab from a user without the Settings permission; the route did not,
        // so any logged-in user of the store reached it by URL. Operator first, the store owner, then `Manage Setting`
        // (App\Services\StoreSettingsAccess::permits), and a refusal is a redirect with an error, never a 401.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put('app_setting_tab', $request->app_setting_tab);
        
        $firebase_enabled = !empty($request->firebase_enabled) ? $request->firebase_enabled : 'off';
        $fcm_Key = !empty($request->fcm_Key) ? $request->fcm_Key : '';

        $post['firebase_enabled'] = $firebase_enabled;
        $post['fcm_Key'] = $fcm_Key;
        foreach ($post as $key => $data) {
            // P1-PLT-07: this write was addressed by (name, saving user) with NO `store_id` AT ALL. For a merchant who runs more than
            // one store that found the row they had written in ANOTHER of their stores, and the payload's `store_id` then MOVED it here
            // -- so saving Firebase settings in one store blanked them in the other. Addressed by (name, store) now, like every other
            // writer, with ownership settled by App\Services\SettingOwnership.
            Setting::putForStore($key, $data, getCurrentStore(), auth()->user()->id);
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

            // P2.5 area-wide-no-withinput-witherrors (CLASS A), same shape as the store_slug
            // site above. ONLY the two validators in this controller that actually call
            // fails() are touched -- :266, :336, :603 and :611 build a validator and never
            // consult it, and activating any of them would refuse posts that succeed today.
            return redirect()->back()
                ->with('error', implode(' ', $messages->all()))
                ->withErrors($validator)
                ->withInput($request->except(['_token', 'password', 'password_confirmation']));
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
            $upload = Utility::upload_file($request, $theme_image, $fileName, $dir, \App\Services\PlatformUploadPolicy::imageRules(['svg']), $theme_image);
            if ($upload['flag'] == '0') {
                // P1-SEC-73. This site could not be refused while the sixth argument skipped the validator, and it answered with a redirect
                // where its only caller reads `$upload['error']` -- HTTP 500 on any refusal. It answers in its own shape, and gives back what
                // it charged: nothing was stored.
                $owner = \App\Models\User::find(\Auth::user()->creatorId());
                if ($owner) {
                    // The fourth site of the same arithmetic; see App\Support\Storage\StorageUsage.
                    $owner->storage_limit = \App\Support\Storage\StorageUsage::persist(
                        \App\Support\Storage\StorageUsage::minus($owner->storage_limit, $image_size)
                    );
                    $owner->save();
                }

                return ["error" => true, "data" => $upload['msg']];
            }
            $img_path = '';
            return ["error" => false, "data" => $upload];
        } else {
            return ["error" => true, "data" => __('Plan storage limit is over so please upgrade the plan.')];
        }
    }

    /**
     * `POST product-image-delete` (name `product.image.delete`) -- unlink ONE image from the
     * caller's own store folder.  GAP-268's last declared entry.
     *
     * WHY THIS EXISTS AS A METHOD RATHER THAN THE ROUTE BEING WITHDRAWN. It was the ninth and final
     * entry in `EveryRouteActionExistsTest::KNOWN_BROKEN`, and the only one of the original 73 that
     * was broken IN USE rather than orphaned: `resources/views/AppSetting/mobile_content.blade.php`
     * calls `route('product.image.delete')` inside a RENDERED view, so withdrawing the route throws
     * `RouteNotFoundException` at render time and 500s the whole Mobile Screen Content page -- worse
     * than the defect it would close.
     *
     * AN UNLINK KEYED ON REQUEST INPUT IS THE P1-SEC-71 SHAPE, so the control is layered and each
     * layer is named with what it alone would and would not stop:
     *
     *   1. `instanceof User` FIRST, before anything asks for an identity -- and on THIS handler that
     *      is load-bearing, not ceremony. `getCurrentStore()` reads `auth()->user()` and returns the
     *      HARD-CODED LITERAL 1 for any principal whose `type` is `super admin`
     *      (`app/Helper/helper.php`). `customers` has a fillable `type` column, so a `Customer` row
     *      typed `super admin` would be handed store 1's folder by that helper. The class check is
     *      what stops the question being asked.
     *   2. Operator FIRST, then `Manage Store Setting` -- the permission this controller's own
     *      `index()` gates on. Operator-first because Laratrust here has
     *      `permissions_as_gates => false` and no super-admin bypass, so `isAbleTo()` ALONE refuses
     *      the platform operator.
     *   3. FAIL CLOSED ON A STORE THAT IS NOT A POSITIVE INTEGER. `getCurrentStore()` answers `null`
     *      for a merchant with no store and no `current_store`. `filter_var(null, VALIDATE_INT)` is
     *      `false` and `false` concatenates as `''`, so without this layer the prefix would be the
     *      literal `uploads//` -- NOT `uploads/`, which this said until the independent review
     *      measured the concatenation. The effect is the same (nothing is confined) and the string
     *      is not, which matters because two committed assertion messages quoted the wrong one.
     *      That is how P1-SEC-71 happened.
     *   4. THE PREFIX COMES FROM THE PRINCIPAL -- AND IT IS IMPOSED ON THE RESOLVED PATH, WHICH IS A
     *      CORRECTION. This layer exists for the DEGENERATE STORED DATA, and that is measured, not
     *      hypothetical: the uploader is `$dir = 'uploads/' . getCurrentStore()` (this file, four
     *      sites), so a file uploaded while the store was null sits at `uploads/<file>` with NO
     *      numeric segment -- and layer 5's tenant rule skips a first segment that is not digits.
     *      Without layer 4 any merchant could delete those.
     *
     *      THE FIRST VERSION OF THIS LAYER TESTED THE UNRESOLVED REQUEST STRING, AND THAT WAS A LIVE
     *      DEFECT FOUND BY THE INDEPENDENT REVIEW OF THIS VERY CHANGE. "Only the leaf comes from the
     *      request" was false: the leaf is itself a path, so `uploads/<myStore>/../profile/<victim>`
     *      begins with the principal's prefix, resolves to `uploads/profile/<victim>`, lands on a
     *      first segment that is not digits -- and so skipped layer 5's tenant rule too. One
     *      segment of traversal reached every panel user's avatar, every store logo, the loose files
     *      directly under `uploads/`, and `uploads/receipt/<file>`: bank-transfer plan-payment
     *      receipts, which this project had ALREADY decided the payer must not be able to erase
     *      (`BulkDeleteTenantGuard` puts `plan-order` in `PLATFORM_GLOBAL` for exactly that reason).
     *      So the fix is not hardening; it closed self-serve destruction of payment evidence.
     *
     *      The prefix is now passed to `StoredUpload::delete()` as a confinement and compared
     *      against the `realpath`. The string test stays as a cheap early refusal. It is
     *      deliberately NOT re-implemented here as a second `realpath()`: two resolutions of one
     *      string can disagree, and that disagreement is what this defect was.
     *   5. `StoredUpload::delete()` IS THE REAL CONTROL, reused rather than re-implemented: it is
     *      what `Product`, `Category` and `Utility` already delete through. It `realpath()`s both
     *      sides, so `..`, a symlink and a percent-decoded traversal all collapse BEFORE the
     *      comparison; it requires the result to sit strictly under `base_path('uploads')`; it
     *      refuses the platform defaults; and it re-reads the tenant segment FROM THE RESOLVED PATH,
     *      so `uploads/A/../B/x.png` is judged as store B. **That tenant rule only fires when the
     *      resolved first segment is a run of DIGITS** -- correct for the question it answers
     *      ("whose store folder is this"), and the reason layer 4 had to be fixed rather than
     *      layer 5: a traversal landing on `profile/` or `receipt/` has no tenant segment to read,
     *      so there was nothing for layer 5 to refuse. This file previously claimed layer 5 was why
     *      the traversal failed; it was true only for the digit case.
     *
     * THE OWNER ID IS `creatorId()`, not the store id: that is the argument
     * `StoredUpload::mayTouchStoreFolder()` expects, and it is what lets a merchant who runs several
     * stores reach their own folders while reaching nobody else's.
     *
     * WHAT THIS DELIBERATELY DOES NOT DO: it does not touch the stored `theme_json`. The view's own
     * success callback re-submits the form, so the record is updated by that resubmission; a handler
     * that also rewrote the JSON would be a second writer of the same document.
     *
     * AND ONE MEASURED FACT, so this does not read as a feature being restored: THE UI CANNOT REACH
     * THIS ENDPOINT TODAY. `image_delete` appears exactly TWICE in the whole Blade tree, both times
     * as the selector `$('body').on('click', '.image_delete', ...)`; NO element anywhere carries
     * `class="image_delete"`. The visible affordance is `class="action-item deleteRecord"
     * data-name="{{ $file_pathh }}"`, and `.deleteRecord` has no handler in that view either. Both
     * halves of the wiring are broken, in opposite directions. This method removes a guaranteed 500
     * and restores no capability a merchant currently has; which affordance is meant to win is a UI
     * decision and is reported rather than guessed at here.
     *
     * Driven in all six directions by `Tests\Feature\Security\AppSettingImageDeleteDoorTest`.
     */
    public function image_delete(Request $request)
    {
        $user = auth()->user();

        /* Layer 1 -- see the docblock: the class check must precede getCurrentStore(). */
        if (! $user instanceof User) {
            return response()->json(['status' => false, 'message' => __('Permission denied.')], 403);
        }

        /* Layer 2 -- operator first, because isAbleTo() alone refuses the operator. */
        if (! UserPolicy::isOperator($user) && ! $user->isAbleTo('Manage Store Setting')) {
            return response()->json(['status' => false, 'message' => __('Permission denied.')], 403);
        }

        /* Layer 3 -- a store that is not a positive integer fails closed. */
        $storeId = filter_var(getCurrentStore(), FILTER_VALIDATE_INT);

        if ($storeId === false || $storeId < 1) {
            return response()->json(['status' => false, 'message' => __('Permission denied.')], 403);
        }

        /*
         * Layer 4 -- the prefix is the principal's. The cheap string test refuses an absolute path
         * and another store's folder before any filesystem call; it is NOT the control, because the
         * leaf is itself a path (see the docblock). The control is the same prefix imposed on the
         * RESOLVED path, by the one resolution inside StoredUpload.
         */
        $path = (string) $request->input('image');
        $prefix = 'uploads/' . $storeId . '/';

        if (! str_starts_with($path, $prefix)) {
            return response()->json(['status' => false, 'message' => __('Permission denied.')], 403);
        }

        /* Layer 5 -- the real control, now carrying layer 4's prefix as a confinement. */
        $deleted = StoredUpload::delete($path, $user->creatorId(), 'uploads/' . $storeId);

        return response()->json([
            'status' => $deleted,
            'message' => $deleted ? __('Image successfully deleted.') : __('Permission denied.'),
        ], $deleted ? 200 : 403);
    }
}
