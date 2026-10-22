<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\Setting;
use App\Models\Theme;
use App\Models\User;
use App\Models\Utility;
use App\Payments\RetiredGateways;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use \WhichBrowser\Parser;
use App\Mail\TestMail;
use App\Models\Customer;
use App\Models\PixelFields;
use Illuminate\Support\Facades\Cookie;
use App\Models\{Webhook, WhatsappMessage, Plan, Country, State, City};
use App\Models\Tax;
use App\Models\TaxOption;
use App\Models\EmailTemplate;
use App\Models\ApikeySetiings;
use App\Models\ShippingZone;
use App\Models\ShippingMethod;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Models\CurrencyRate;
use App\Services\StoreCurrencyConversionService;

use App\Models\OtpIntegration;

class SettingController extends Controller
{


public function saveDeliverySyria(Request $request)
{
    $currentStoreId = getCurrentStore();
    $post = $request->all();

 

    // 2. إرسال الطلب إلى الـ API الخارجي
    try {
        $response = Http::post('https://deliverysyria.com/api/secret/cheack', [
            'secret' => $request->delivery_syria_token// القيمة القادمة من الفورم
        ]);

     if ($response->successful()) {
    $storeId = getCurrentStore();
    $data = $response->json('data'); // استخراج البيانات من الاستجابة

    foreach ($data as $t) {
    // 1. البحث عن المنطقة بنفس الـ state_id
    $shippingZone = ShippingZone::where('state_id', $t['code'])
        ->where('store_id', $storeId)
        ->first();

    if ($shippingZone) {
        // إذا موجودة: تأكد أن "Delivery Syria" مضافة للعمود دون تكرار
        $methods = explode(',', $shippingZone->shipping_method);
        if (!in_array('Delivery Syria', $methods)) {
            $methods[] = 'Delivery Syria';
            $shippingZone->update(['shipping_method' => implode(',', $methods)]);
        }
    } else {
        // إذا غير موجودة: أنشئ واحدة جديدة
        $shippingZone = ShippingZone::create([
            'zone_name'       => $t['governorate'],
            'country_id'      => '215',
            'state_id'        => $t['code'],
            'shipping_method' => 'Delivery Syria',
            'store_id'        => $storeId,
        ]);
    }

    // 2. تحديث التكلفة (سواء كانت موجودة أو تم إنشاؤها للتو)
    // نستخدم updateOrCreate للتأكد من تعديل السعر فقط إذا وجدت الطريقة
    ShippingMethod::updateOrCreate(
        [
            'zone_id'     => $shippingZone->id,
            'store_id'    => $storeId,
            'method_name' => 'Delivery Syria'
        ],
        [
            'cost' => $t['base_cost'], // دائماً يتم تحديث السعر بناءً على الريكويست
        ]
    );
}


$u=auth()->user();
$u->deliverysyria_secret=$request->delivery_syria_token;
$u->save();
    
    return redirect()->back()->with('success', __('Delivery Syria data updated successfully.'));
} else {
            // السيرفر رد بخطأ (مثل 404 أو 403)

            return redirect()->back()->with('error', __('Token updated but verification failed.'));
        }
    } catch (\Exception $e) {
        // في حال تعطل سيرفر الـ Ngrok أو مشكلة في الاتصال
        return redirect()->back()->with('error', __('Could not connect to verification server.'));
    }
}

public function saveDeliverySyria1(Request $request)
{

$u=auth()->user();
$u->deliverysyria_secret=$request->delivery_syria_token;
$u->save();
    
    return redirect()->back()->with('success', __('Delivery Syria token updated successfully.'));
}








    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        return view('setting.index');
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
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function StorageSettings(Request $request)
    {
        // P1-SEC-30: a fixed-name credential writer. The menu hides this tab from a user without the Settings permission; the route did not,
        // so any logged-in user of the store reached it by URL. Operator first, the store owner, then `Manage Setting`
        // (App\Services\StoreSettingsAccess::permits), and a refusal is a redirect with an error, never a 401.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'storage_setting']);
        $theme_id = APP_THEME();
        if (isset($request->storage_setting) && $request->storage_setting == 'local') {

            $request->validate(
                [

                    'local_storage_validation' => 'required',
                    'local_storage_max_upload_size' => 'required',
                ]
            );

            $post['storage_setting'] = $request->storage_setting;
            $local_storage_validation = implode(',', $request->local_storage_validation);
            $post['local_storage_validation'] = $local_storage_validation;
            $post['local_storage_max_upload_size'] = $request->local_storage_max_upload_size;
        }

        if (isset($request->storage_setting) && $request->storage_setting == 's3') {
            $request->validate(
                [
                    's3_key' => 'required',
                    's3_secret' => 'required',
                    's3_region' => 'required',
                    's3_bucket' => 'required',
                    's3_url' => 'required',
                    's3_endpoint' => 'required',
                    's3_max_upload_size' => 'required',
                    's3_storage_validation' => 'required',
                ]
            );

            $post['storage_setting'] = $request->storage_setting;
            $post['s3_key'] = $request->s3_key;
            $post['s3_secret'] = $request->s3_secret;
            $post['s3_region'] = $request->s3_region;
            $post['s3_bucket'] = $request->s3_bucket;
            $post['s3_url'] = $request->s3_url;
            $post['s3_endpoint'] = $request->s3_endpoint;
            $post['s3_max_upload_size'] = $request->s3_max_upload_size;
            $s3_storage_validation = implode(',', $request->s3_storage_validation);
            $post['s3_storage_validation'] = $s3_storage_validation;
        }

        if (isset($request->storage_setting) && $request->storage_setting == 'wasabi') {
            $request->validate(
                [
                    'wasabi_key' => 'required',
                    'wasabi_secret' => 'required',
                    'wasabi_region' => 'required',
                    'wasabi_bucket' => 'required',
                    'wasabi_url' => 'required',
                    'wasabi_root' => 'required',
                    'wasabi_max_upload_size' => 'required',
                    'wasabi_storage_validation' => 'required',
                ]
            );
            $post['storage_setting'] = $request->storage_setting;
            $post['wasabi_key'] = $request->wasabi_key;
            $post['wasabi_secret'] = $request->wasabi_secret;
            $post['wasabi_region'] = $request->wasabi_region;
            $post['wasabi_bucket'] = $request->wasabi_bucket;
            $post['wasabi_url'] = $request->wasabi_url;
            $post['wasabi_root'] = $request->wasabi_root;
            $post['wasabi_max_upload_size'] = $request->wasabi_max_upload_size;
            $wasabi_storage_validation = implode(',', $request->wasabi_storage_validation);
            $post['wasabi_storage_validation'] = $wasabi_storage_validation;
        }

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

        return redirect()->back()->with('success', 'Storage setting successfully updated.');
    }

    public function BusinessSettings(Request $request)
    {

        // P1-SEC-27. This handler writes EVERY posted name into `settings` for the caller's store, and its form is on the Settings
        // screen, which settingForm() already refuses to anyone without `Manage Setting`; the route itself was open to every logged-in
        // user of the store, so the write asks for it too (App\Services\StoreSettingsAccess).
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'brand_setting']);
        // Get the authenticated user
        $user = auth()->user();

        $dir = 'uploads/' .getCurrentStore();

        // Get data from the request
        $post = $request->all();

        $SITE_RTL = !isset($request->SITE_RTL) ? 'off' : 'on';
        // Check user type
        if (auth()->user()->type == 'super admin') {
            $dir =  Storage::url('uploads/logo');
            if ($request->logo_dark) {
                $theme_image = $request->logo_dark;
                $fileName = $defaultName = 'logo-dark.png';
                // $fileName = rand(10, 100) . '_' . time() . "_" . $request->logo_dark->getClientOriginalName();
                $path = Utility::upload_file($request, 'logo_dark', $fileName, $dir, []);
                if ($path['flag'] == '0') {
                    return redirect()->back()->with('error', $path['msg']);
                } else {
                    $where = ['name' => 'logo_dark'];
                    $Setting = Setting::where($where)->first();
                    if (!empty($Setting) && $defaultName != 'logo-dark.png') {
                        $image_path = 'uploads/logo/' . $fileName;
                        if (File::exists($image_path)) {
                            File::delete($image_path);
                        }
                    }
                    $post['logo_dark'] = $path['url'];
                }
            }
            if ($request->logo_light) {
                $theme_image = $request->logo_light;
                $fileName = $defaultName = 'logo-light.png';
                // $fileName = rand(10, 100) . '_' . time() . "_" . $request->logo_light->getClientOriginalName();
                $path = Utility::upload_file($request, 'logo_light', $fileName, $dir, []);

                if ($path['flag'] == '0') {
                    return redirect()->back()->with('error', $path['msg']);
                } else {
                    $where = ['name' => 'logo_light'];
                    $Setting = Setting::where($where)->first();

                    if (!empty($Setting) && $defaultName != 'logo-light.png') {
                        $image_path = 'uploads/logo/' . $fileName;
                        if (File::exists($image_path)) {
                            File::delete($image_path);
                        }
                    }
                    $post['logo_light'] = $path['url'];
                }
            }

            if ($request->favicon) {
                $theme_image = $request->favicon;
                $fileName = $defaultName = 'favicon.png';
                // $fileName = rand(10, 100) . '_' . time() . "_" . $request->favicon->getClientOriginalName();
                $path = Utility::upload_file($request, 'favicon', $fileName, $dir, []);

                if ($path['flag'] == '0') {
                    return redirect()->back()->with('error', $path['msg']);
                } else {
                    $where = ['name' => 'favicon'];
                    $Setting = Setting::where($where)->first();

                    if (!empty($Setting) && $defaultName != 'favicon.png') {
                        $image_path = 'uploads/logo/' . $fileName;
                        if (File::exists($image_path)) {
                            File::delete($image_path);
                        }
                    }
                    $post['favicon'] = $path['url'];
                }
            }
        } else {
            $totalImageSize = 0;
            if ($request->hasFile('logo_dark')) {
                $totalImageSize += $request->file('logo_dark')->getSize();
            }
            if ($request->hasFile('logo_light')) {
                $totalImageSize += $request->file('logo_light')->getSize();
            }
            if ($request->hasFile('favicon')) {
                $totalImageSize += $request->file('favicon')->getSize();
            }
            $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
            if ($result != 1) {
                return redirect()->back()->with('error', $result);
            }
            if ($request->logo_dark) {
                $theme_image = $request->logo_dark;
                $defaultName = 'logo-dark.png';
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->logo_dark->getClientOriginalName();
                $path = Utility::upload_file($request, 'logo_dark', $fileName, $dir, []);

                if ($path['flag'] == '0') {
                    return redirect()->back()->with('error', $path['msg']);
                } else {
                    $where = ['name' => 'logo_dark', 'store_id' => getCurrentStore()];
                    $Setting = Setting::where($where)->first();

                    if (!empty($Setting) && $Setting->value != 'storage/uploads/logo/logo-dark.png') {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $Setting->value);
                        // $removePath = Utility::remove_file($Setting->value);
                        // if ($removePath['flag'] == '0') {
                        //     return redirect()->back()->with('error', $removePath['msg']);
                        // }
                    }
                    $post['logo_dark'] = $path['url'];
                }
            }
            if ($request->logo_light) {
                $theme_image = $request->logo_light;
                $defaultName = 'logo-light.png';
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->logo_light->getClientOriginalName();
                $path = Utility::upload_file($request, 'logo_light', $fileName, $dir, []);

                if ($path['flag'] == '0') {
                    return redirect()->back()->with('error', $path['msg']);
                } else {
                    $where = ['name' => 'logo_light', 'store_id' => getCurrentStore()];
                    $Setting = Setting::where($where)->first();

                    if (!empty($Setting) && $Setting->value != 'storage/uploads/logo/logo-light.png') {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $Setting->value);
                        // $removePath = Utility::remove_file($Setting->value);
                        // if ($removePath['flag'] == '0') {
                        //     return redirect()->back()->with('error', $removePath['msg']);
                        // }
                    }
                    $post['logo_light'] = $path['url'];
                }
            }
            if ($request->favicon) {
                $theme_image = $request->favicon;
                $defaultName = 'favicon.png';
                $fileName = rand(10, 100) . '_' . time() . "_" . $request->favicon->getClientOriginalName();
                $path = Utility::upload_file($request, 'favicon', $fileName, $dir, []);

                if ($path['flag'] == '0') {
                    return redirect()->back()->with('error', $path['msg']);
                } else {
                    $where = ['name' => 'favicon', 'store_id' => getCurrentStore()];
                    $Setting = Setting::where($where)->first();

                    if (!empty($Setting) && $Setting->value != 'storage/uploads/logo/favicon.png') {
                        Utility::changeStorageLimit(\Auth::user()->creatorId(), $Setting->value);
                        // $removePath = Utility::remove_file($Setting->value);
                        // if ($removePath['flag'] == '0') {
                        //     return redirect()->back()->with('error', $removePath['msg']);
                        // }
                    }
                    $post['favicon'] = $path['url'];
                }
            }
        }

        $default_language = $request->has('default_language') ? $request->default_language : 'en';
        if (auth()->user()->type == 'super admin') {
            $user = auth()->user();
            $user->default_language = $default_language;
            $user->save();

            $store = getStoreById($user->current_store);
            $store->default_language = $default_language;
            $store->save();
        } else {
            $user = auth()->user();
            $user->default_language = $default_language;
            $user->save();

            $store = getStoreById($user->current_store);
            $store->default_language = $default_language;
            $store->save();
        }

        // if (!empty($request->title_text) || !empty($request->footer_text) || !empty($request->color) || !empty($request->email_verification) || !empty($request->display_landing)) {
        $SITE_RTL = $request->has('SITE_RTL') ? $request->SITE_RTL : 'off';
        $post['SITE_RTL'] = $SITE_RTL;

        $SIGNUP = $request->has('SIGNUP') ? $request->SIGNUP : 'off';
        $post['SIGNUP'] = $SIGNUP;

        $taxes = $request->has('taxes') ? $request->taxes : 'off';
        $post['taxes'] = $taxes;

        $display_landing = $request->has('display_landing') ? $request->display_landing : 'off';
        $post['display_landing'] = $display_landing;


        $email_verification = $request->has('email_verification') ? $request->email_verification : 'off';
        $post['email_verification'] = $email_verification;

        if (!isset($request->cust_theme_bg)) {
            $post['cust_theme_bg'] = 'off';
        }
        if (!isset($request->cust_darklayout)) {
            $post['cust_darklayout'] = 'off';
        }

        if (isset($request->color) && $request->color_flag == 'false') {
            $post['color'] = $request->color;
        } elseif (isset($request->custom_color) && $request->color_flag == 'true') {
            $post['custom_color'] = $request->custom_color;
            $post['color'] = $request->custom_color;
        }
        // else
        // {
        //     $post['color'] = $request->custom_color;
        // }
        unset($post['default_language']);
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
        // }
        return redirect()->back()->with('success', __('Brand setting successfully updated.'));
    }

    public function saveEmailSettings(Request $request)
    {
        session()->put(['setting_tab' => 'email_setting']);
        // P1-SEC-18 F5. Overwrites the SMTP credentials; see settingForm().
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        // Validate the incoming request data
        $validator = \Validator::make(
            $request->all(),
            [
                'mail_driver' => 'required|string|max:50',
                'mail_host' => 'required|string|max:50',
                'mail_port' => 'required|string|max:50',
                'mail_username' => 'required|string|max:50',
                'mail_password' => 'required|string|max:50',
                'mail_encryption' => 'required|string|max:50',
                'mail_from_address' => 'required|string|max:50',
                'mail_from_name' => 'required|string|max:50',
            ]
        );

        // If validation fails, redirect back with the first error message
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        // Prepare data for database insertion/update
        $post['email_setting'] = $request->email_setting ?? 'SMTP';
        $post['MAIL_DRIVER'] = $request->mail_driver ?? "";
        $post['MAIL_HOST'] = $request->mail_host ?? "";
        $post['MAIL_PORT'] = $request->mail_port?? "";
        $post['MAIL_USERNAME'] = $request->mail_username ?? "";
        $post['MAIL_PASSWORD'] = $request->mail_password ?? "" ;
        $post['MAIL_ENCRYPTION'] = $request->mail_encryption ?? "";
        $post['MAIL_FROM_NAME'] = $request->mail_from_name?? "";
        $post['MAIL_FROM_ADDRESS'] = $request->mail_from_address ?? "";

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

        // Redirect back with success message
        return redirect()->back()->with('success', __('Setting successfully updated.'));
    }

    public function TestMail(Request $request)
    {
        $email_setting = $request->all();
        $settings = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();
        $user = auth()->user();

        $data = [];
        $data['mail_driver'] = $request->mail_driver;
        $data['mail_host'] = $request->mail_host;
        $data['mail_port'] = $request->mail_port;
        $data['mail_username'] = $request->mail_username;
        $data['mail_password'] = $request->mail_password;
        $data['mail_encryption'] = $request->mail_encryption;
        $data['mail_from_address'] = $request->mail_from_address;
        $data['mail_from_name'] = $request->mail_from_name;

        return view('setting.test_mail', compact('email_setting', 'settings', 'data'));
    }

    public function testSendMail(Request $request)
    {
        session()->put(['setting_tab' => 'email_setting']);
        $validator = \Validator::make(
            $request->all(),
            [
                'mail_driver' => 'required',
                'mail_host' => 'required',
                'mail_port' => 'required',
                'mail_username' => 'required',
                'mail_password' => 'required',
                'mail_from_address' => 'required',
                'mail_from_name' => 'required',
                'email' => 'required|email',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();

            return response()->json(
                [
                    'is_success' => false,
                    'message' => $messages->first(),
                ]
            );
        }

        try {
            SetConfigEmail($request);

            Mail::to($request->email)->send(new TestMail($request));

            return response()->json(
                [
                    'is_success' => true,
                    'message' => __('Email send Successfully'),
                ]
            );
        } catch (\Exception $e) {
            return response()->json(
                [
                    'is_success' => false,
                    'message' => $e->getMessage(),
                ]
            );
        }
    }

    public function CookieSettings(Request $request)
    {

        // P1-SEC-27. This handler writes EVERY posted name into `settings` for the caller's store, and its form is on the Settings
        // screen, which settingForm() already refuses to anyone without `Manage Setting`; the route itself was open to every logged-in
        // user of the store, so the write asks for it too (App\Services\StoreSettingsAccess).
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'cookie_setting']);
        $validator = \Validator::make(
            $request->all(),
            [
                'cookie_title'                  => 'required',
                'cookie_description'            => 'required',
                'strictly_cookie_title'         => 'required',
                'strictly_cookie_description'   => 'required',
                'more_information_title'        => 'required',
                'contactus_url'                 => 'required',
            ]
        );
        $post = $request->all();
        unset($post['_token']);

        $post['enable_cookie'] = isset($request->enable_cookie) ? 'on' : 'off';
        $post['cookie_logging'] = isset($request->cookie_logging) ? 'on' : 'off';

        if ($post['enable_cookie'] == 'on') {
            $post['cookie_title']                   = $request->cookie_title;
            $post['cookie_description']             = $request->cookie_description;
            $post['strictly_cookie_title']          = $request->strictly_cookie_title;
            $post['strictly_cookie_description']    = $request->strictly_cookie_description;
            $post['more_information_title']         = $request->more_information_title;
            $post['contactus_url']                  = $request->contactus_url;
        }
        $settings = Utility::cookies();
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

        return redirect()->back()->with('success', 'Cookie setting successfully saved.');
    }

    public function CookieConsent(Request $request)
    {
        $settings = Utility::Setting();
        if (isset($settings['enable_cookie']) && isset($settings['cookie_logging']) && $settings['enable_cookie'] == "on" && $settings['cookie_logging'] == "on") {
            $whichbrowser = new \WhichBrowser\Parser($_SERVER['HTTP_USER_AGENT']);
            // Generate new CSV line
            $browser_name = $whichbrowser->browser->name ?? null;
            $os_name = $whichbrowser->os->name ?? null;
            $browser_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? mb_substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : null;
            $device_type = Utility::get_device_type($_SERVER['HTTP_USER_AGENT']);

            $ip = $_SERVER['REMOTE_ADDR'];
            $query = @unserialize(file_get_contents('http://ip-api.com/php/'.$ip));
            $date = (new \DateTime())->format('Y-m-d');
            $time = (new \DateTime())->format('H:i:s') . ' UTC';

            $new_line = implode(',', [
                $ip,
                $date,
                $time,
                json_encode($request['cookie']),
                $device_type,
                $browser_language,
                $browser_name,
                $os_name,
                isset($query['country']) ? $query['country'] : '-',
                isset($query['region']) ? $query['region'] : '-',
                isset($query['regionName']) ? $query['regionName'] : '-',
                isset($query['city']) ? $query['city'] : '-',
                isset($query['zip']) ? $query['zip'] : '-',
                isset($query['lat']) ? $query['lat'] : '-',
                isset($query['lon']) ? $query['lon'] : '-'
            ]);

            if (!file_exists(storage_path() . '/uploads/sample/cookie_data.csv')) {
                $first_line = 'IP,Date,Time,Accepted cookies,Device type,Browser language,Browser name,OS Name,Country,Region,RegionName,City,Zipcode,Lat,Lon';
                file_put_contents(storage_path() . '/uploads/sample/cookie_data.csv', $first_line . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
            file_put_contents(storage_path() . '/uploads/sample/cookie_data.csv', $new_line . PHP_EOL, FILE_APPEND | LOCK_EX);

            return response()->json('success');
        } else {
            return response()->json('error');
        }
    }

    public function RecaptchaSetting(Request $request)
    {
        session()->put(['setting_tab' => 'recaptcha_setting']);
        if (\Auth::user()->type == 'super admin') {
            $user = Auth::user();
            $rules = [];
            $rules['google_recaptcha_key']      = 'required|string|max:50';
            $rules['google_recaptcha_secret']   = 'required|string|max:50';
            $validator = \Validator::make($request->all(), $rules);
            if ($validator->fails()) {
                return redirect()->back()->with('error', $validator->getMessageBag()->first());
            }
            $data_recaptcha = [
                'RECAPTCHA_MODULE'  => (isset($request->recaptcha_module)  ? 'yes' : 'no') ?? 'no',
                'NOCAPTCHA_SITEKEY' => $request->google_recaptcha_key,
                'NOCAPTCHA_SECRET'  => $request->google_recaptcha_secret,
                'NOCAPTCHA_VERSON'  => $request->google_recaptcha_version ?? 'v2',
            ];

            $settingQuery = Setting::query();
            foreach ($data_recaptcha as $key => $data) {
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
                return redirect()->back()->with('success', __('Recaptcha Settings updated successfully'));
            } else {
                return redirect()->back()->with('error', __('Something is wrong'));
            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function ChatgptSettings(Request $request)
    {
        session()->put(['setting_tab' => 'chatgpt_setting']);
        if (auth()->user()->type == 'super admin') {
            $key_arr = $request->api_key;
            foreach ($key_arr as  $data) {
                if ($data != '' && !empty($data)) {
                    ApikeySetiings::updateOrCreate([
                        'key' => $data,
                        'created_by' => auth()->user()->id
                    ]);
                }
            }

            ApikeySetiings::whereNotIn('key', $key_arr)->delete();

            if (!empty($request->chat_gpt_model)) {
                $post = [];
                $post['chat_gpt_model'] = $request->chat_gpt_model;
                unset($post['_token']);
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
            }
            return redirect()->back()->with('success', __('Chatgpykey successfully saved.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
    }

    public function CustomizeSetting(Request $request)
    {

        // P1-SEC-27. This handler writes EVERY posted name into `settings` for the caller's store, and its form is on the Settings
        // screen, which settingForm() already refuses to anyone without `Manage Setting`; the route itself was open to every logged-in
        // user of the store, so the write asks for it too (App\Services\StoreSettingsAccess).
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'style_setting']);
        $post = $request->all();
        unset($post['_token']);
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

        return redirect()->back()->with('success', 'Customize Css successfully updated.');
    }

    public function LoyalityProgramSettings(Request $request)
    {
        // P1-SEC-27. The web twin of Api\DashboardController::LoyalitySetting, which asks the same question; the reward point decides
        // what a shopper's points are worth.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        session()->put(['setting_tab' => 'loyality_pro_setting']);
        $theme_id = !empty(APP_THEME()) ? APP_THEME() : APP_THEME();

        $loyality_program_enabled = !empty($request->loyality_program_enabled) ? $request->loyality_program_enabled : 'off';
        $reward_point = !empty($request->reward_point) ? $request->reward_point : 0;

        $post['loyality_program_enabled'] = $loyality_program_enabled;
        $post['reward_point'] = $reward_point;
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
            return redirect()->back()->with('success', __('Settings updated successfully'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function WoocommerceSettings(Request $request)
    {
        // P1-SEC-30: a fixed-name credential writer. The menu hides this tab from a user without the Settings permission; the route did not,
        // so any logged-in user of the store reached it by URL. Operator first, the store owner, then `Manage Setting`
        // (App\Services\StoreSettingsAccess::permits), and a refusal is a redirect with an error, never a 401.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'woocom_setting']);
        $theme_id = !empty(APP_THEME()) ? APP_THEME() : '';

        $post['woocommerce_setting_enabled'] = $request->woocommerce_setting_enabled;
        if (isset($request->woocommerce_setting_enabled) && $request->woocommerce_setting_enabled == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    // P1-SEC-06. `required|string` accepted anything, and the value is interpolated into
                    // the importers' outbound base address, so it had to be judged somewhere. It IS judged
                    // at the read (ImportStoreUrl::woocommerceBase, which fails closed), and this is the
                    // layer that gives the merchant a readable reason instead of a failed import.
                    'woocommerce_store_url' => ['required', 'string', 'max:255', function ($attribute, $value, $fail) {
                        // SYNTAX ONLY, no DNS -- see ImportStoreUrl::isWellFormedWoocommerceBase for why
                        // a write-time resolution is both an availability regression and a TOCTOU check
                        // that buys nothing. The address itself is judged immediately before each fetch.
                        if (! \App\Services\ImportStoreUrl::isWellFormedWoocommerceBase($value)) {
                            $fail(__('The WooCommerce store URL must be an http or https address with no credentials in it.'));
                        }
                    }],
                    'woocommerce_consumer_key' => 'required|string',
                    'woocommerce_consumer_secret' => 'required|string',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }


        $post['woocommerce_store_url'] = $request->woocommerce_store_url;
        $post['woocommerce_consumer_key'] = $request->woocommerce_consumer_key;
        $post['woocommerce_consumer_secret'] = $request->woocommerce_consumer_secret;
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
            return redirect()->back()->with('success', __('Woocommerce setting successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function shopifySettings(Request $request)
    {
        // P1-SEC-30: a fixed-name credential writer. The menu hides this tab from a user without the Settings permission; the route did not,
        // so any logged-in user of the store reached it by URL. Operator first, the store owner, then `Manage Setting`
        // (App\Services\StoreSettingsAccess::permits), and a refusal is a redirect with an error, never a 401.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'shopify_setting']);
        $theme_id = !empty(APP_THEME()) ? APP_THEME() : '';

        $post['shopify_setting_enabled'] = $request->shopify_setting_enabled;
        if (isset($request->shopify_setting_enabled) && $request->shopify_setting_enabled == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    // P1-SEC-06. This field is a shop NAME -- one DNS label -- and `required|string` let a
                    // value like `169.254.169.254#` be stored, which ends the host and comments the
                    // `.myshopify.com` suffix out of the thirteen URLs built from it. Judged at the read
                    // too (ImportStoreUrl::shopifyLabel, which fails closed); this layer is the readable
                    // refusal.
                    'shopify_store_url' => ['required', 'string', 'max:63', function ($attribute, $value, $fail) {
                        if (! \App\Services\ImportStoreUrl::isValidShopifyLabel($value)) {
                            $fail(__('The Shopify store name must be the shop name only -- letters, digits and hyphens, with no dots, scheme or slashes.'));
                        }
                    }],
                    'shopify_access_token' => 'required|string',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }


        $post['shopify_store_url'] = $request->shopify_store_url;
        $post['shopify_access_token'] = $request->shopify_access_token;
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
            return redirect()->back()->with('success', __('Shopify setting successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function SystemSettings(Request $request)
    {

        // P1-SEC-27. This handler writes EVERY posted name into `settings` for the caller's store, and its form is on the Settings
        // screen, which settingForm() already refuses to anyone without `Manage Setting`; the route itself was open to every logged-in
        // user of the store, so the write asks for it too (App\Services\StoreSettingsAccess).
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'system_setting']);
        // Get the authenticated user
        $user = auth()->user();

        // Get the theme ID and directory
        $theme_id = APP_THEME();
        $default_language = $request->has('default_language') ? $request->default_language : 'en';
        if (auth()->user()->type == 'super admin') {
            $user = auth()->user();
            $user->default_language = $default_language;
            $user->save();

            $store = getStoreById($user->current_store);
            $store->default_language = $default_language;
            $store->save();
        } else {
            $user = auth()->user();
            $user->default_language = $default_language;
            $user->save();

            $store = getStoreById($user->current_store);
            $store->default_language = $default_language;
            $store->save();
        }
        if (!empty($request->currency_format) || !empty($request->defult_currancy) || !empty($request->default_language) || !empty($request->site_currency_symbol_position) || !empty($request->site_date_format) || !empty($request->site_time_format)) {
            $post = $request->all();
            unset($post['_token']);
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
                return redirect()->back()->with('success', __('System setting successfully updated.'));
            } else {
                return redirect()->back()->with('error', __('Something is wrong'));
            }
        }
    }

    public function customMassage(Request $request, $slug = null)
    {
        session()->put(['setting_tab' => 'whatsapp_msg_setting']);
        $validator = \Validator::make(
            $request->all(),
            [
                'whatsapp_content' => 'required',
                'whatsapp_item_variable' => 'required',
            ]
        );

        if ($validator->fails()) {
            $messages = $validator->getMessageBag();

            return redirect()->back()->with('error', $messages->first());
        }
        $post['whatsapp_item_variable'] = $request->whatsapp_item_variable;
        $post['whatsapp_content'] = $request->whatsapp_content;

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
                    'created_by'    => Auth::user()->id,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]
            );
        }
        if (isset($status)) {
            return redirect()->back()->with('success', __('Whatsapp setting successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function StockSettings(Request $request)
    {
        // P1-SEC-27 (third review). A tab of the Settings screen, which settingForm() refuses to a user without `Manage Setting`;
        // its POST target had no check at all.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }
        session()->put(['setting_tab' => 'stock_setting']);
        $theme_id = !empty(APP_THEME()) ? APP_THEME() : '';
        $validator = \Validator::make(
            $request->all(),
            [
                'low_stock_threshold' => 'required',
                'out_of_stock_threshold' => 'required',
                'notification' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $post['low_stock_threshold'] = $request->low_stock_threshold;
        $post['out_of_stock_threshold'] = $request->out_of_stock_threshold;
        $post['stock_management'] = $request->has('stock_management') ? $request->stock_management : 'off';
        $post['notification'] =  json_encode($request->input('notification'));
        foreach ($post as $key => $data) {
            // P1-PLT-07: addressed by (name, store), not by the saving user -- with `created_by` in the match key a staff save
            // inserted a second row for the same name in the same store instead of updating the owner's. Ownership is settled by
            // App\Services\SettingOwnership, and putForStore() lands on the row the readers serve rather than on the oldest one
            // updateOrCreate would find; its docblock carries the measurement.
            $status = Setting::putForStore($key, $data, getCurrentStore(), auth()->user()->id);
        }
        if (isset($status)) {
            return redirect()->back()->with('success', __('Stock setting successfully updated.'));
        } else {
            return redirect()->back()->with('error', __('Something is wrong'));
        }
    }

    public function WhatsappSettings(Request $request)
    {
        // P1-SEC-30: a fixed-name credential writer. The menu hides this tab from a user without the Settings permission; the route did not,
        // so any logged-in user of the store reached it by URL. Operator first, the store owner, then `Manage Setting`
        // (App\Services\StoreSettingsAccess::permits), and a refusal is a redirect with an error, never a 401.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'whatsapp_setting']);
        $theme_id = !empty(APP_THEME()) ? APP_THEME() : '';

        $post['whatsapp_setting_enabled'] = $request->whatsapp_setting_enabled;
        if (isset($request->whatsapp_setting_enabled) && $request->whatsapp_setting_enabled == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'whatsapp_contact_number' => ['required', 'regex:/^\+[1-9]\d{1,14}$/'],
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }


        $post['whatsapp_contact_number'] = $request->whatsapp_contact_number;

        if ($request->whatsapp_setting_enabled == 'off') {
            $post['whatsapp_contact_number'] = '';
        }

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
        return redirect()->back()->with('success', 'Whatsapp setting successfully updated.');
    }

    public function whatsapp_notification(Request $request)
    {
        session()->put(['setting_tab' => 'whatsapp_notify_setting']);
        $usr = auth()->user();

        if ($usr->type == 'super admin' || $usr->type == 'admin') {
            $WhatsappMessage  = WhatsappMessage::where('user_id', $usr->id)->where('id', $request->notification_id)->first();
            $WhatsappMessage->is_active = $request->status;
            $WhatsappMessage->save();

            return response()->json(['success' => 'WhatsappNotification change successfully.']);
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function whatsapp_notification_setting(Request $request)
    {
        // P1-SEC-30: a fixed-name credential writer. The menu hides this tab from a user without the Settings permission; the route did not,
        // so any logged-in user of the store reached it by URL. Operator first, the store owner, then `Manage Setting`
        // (App\Services\StoreSettingsAccess::permits), and a refusal is a redirect with an error, never a 401.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'whatsapp_notify_setting']);
        $theme_id = !empty(APP_THEME()) ?  APP_THEME() : '';

        $validator = \Validator::make(
            $request->all(),
            [
                'whatsapp_phone_number_id' => 'required|string',
                'whatsapp_access_token' => 'required|string',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }

        $post['whatsapp_phone_number_id'] = $request->whatsapp_phone_number_id;
        $post['whatsapp_access_token'] = $request->whatsapp_access_token;
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
        return redirect()->back()->with('success', 'Whatsapp Business API setting successfully updated.');
    }

    public function Testwhatsappmassage(Request $request)
    {
        $email_setting = $request->all();
        $settings = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();

        $user = auth()->user();

        $data = [];
        $data['whatsapp_phone_number_id'] = $request->whatsapp_phone_number_id;
        $data['whatsapp_access_token'] = $request->whatsapp_access_token;


        return view('setting.test_whatsappmessage', compact('email_setting', 'settings', 'data'));
    }

    public function testSendwhatsappmassage(Request $request)
    {
        session()->put(['setting_tab' => 'whatsapp_notify_setting']);
        $validator = \Validator::make(
            $request->all(),
            [
                'mobile' => 'required',
                'whatsapp_phone_number_id' => 'required',
                'whatsapp_access_token' => 'required',
            ]
        );
        if ($validator->fails()) {
            $messages = $validator->getMessageBag();
            return redirect()->back()->with('error', $messages->first());
        }
        try {

            $url = 'https://graph.facebook.com/v17.0/' . $request->whatsapp_phone_number_id . '/messages';

            $data = array(
                'messaging_product' => 'whatsapp',
                'to' => $request->mobile,
                'type' => 'template',
                'template' => array(
                    'name' => 'hello_world',
                    'language' => array(
                        'code' => 'en_US'
                    )
                )
            );

            $headers = array(
                'Authorization: Bearer ' . $request->whatsapp_access_token,
                'Content-Type: application/json'
            );

            $ch = curl_init($url);

            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $response = curl_exec($ch);

            $responseData = json_decode($response);

            curl_close($ch);

            if (isset($responseData->error)) {

                return redirect()->back()->with('error', $responseData->error->message);
            } else {
                return redirect()->back()->with('successs', 'Massage send Successfully');
            }
        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function TwilioSettings(Request $request)
    {
        // P1-SEC-30: a fixed-name credential writer. The menu hides this tab from a user without the Settings permission; the route did not,
        // so any logged-in user of the store reached it by URL. Operator first, the store owner, then `Manage Setting`
        // (App\Services\StoreSettingsAccess::permits), and a refusal is a redirect with an error, never a 401.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'twilio_setting']);
        $theme_id = !empty(App_THEME()) ? APP_THEME() : '';
        $post['twilio_setting_enabled'] = $request->twilio_setting_enabled;
        if (isset($request->twilio_setting_enabled) && $request->twilio_setting_enabled == 'on') {
            $validator = \Validator::make(
                $request->all(),
                [
                    'twilio_sid' => 'required|string',
                    'twilio_token' => 'required|string',
                    'twilio_from' => 'required|numeric',
                    'twilio_notification_number' => 'required|numeric',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();
                return redirect()->back()->with('error', $messages->first());
            }
        }


        $post['twilio_sid'] = $request->twilio_sid;
        $post['twilio_token'] = $request->twilio_token;
        $post['twilio_from'] = $request->twilio_from;
        $post['twilio_notification_number'] = $request->twilio_notification_number;

        if ($request->twilio_setting_enabled == 'off') {
            $post['twilio_sid'] = '';
            $post['twilio_token'] = '';
            $post['twilio_from'] = '';
            $post['twilio_notification_number'] = '';
        }

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
        return redirect()->back()->with('success', 'Twilio setting successfully updated.');
    }

   public function currencySettings(Request $request)
{
        // P1-SEC-27. The currency tab of the Settings screen. Changing the store currency REWRITES product, variant and wallet
        // amounts and pending COD orders (convertStoreCurrentValues), so this is not "a setting": it needs the permission the screen
        // needs, like its admin-API twins (updateCurrencyApi, updateCurrencyRateApi, toggleCurrencyStatusApi). That also covers the
        // restricted names this handler would write by name: whoever passes this check may write them.
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

    session()->put(['setting_tab' => 'currency_setting']);

    $storeId = getCurrentStore();

    /*
     * العملة الحالية قبل الحفظ
     */
    $oldCurrency = Setting::where('store_id', $storeId)
        ->where('name', 'defult_currancy')
        ->value('value') ?? 'USD';

    $post = $request->all();

    unset($post['_token']);
    unset($post['_method']);

    /*
     * العملة الجديدة المختارة
     */
    if (isset($post['defult_currancy'])) {

        $data = explode('-', $post['defult_currancy']);

        $post['defult_currancy_symbol'] = $data[0];
        $post['CURRENCY'] = $data[0];

        $post['defult_currancy'] = $data[1];
        $post['CURRENCY_NAME'] = $data[1];

    } else {

        $post['defult_currancy'] = 'USD';
        $post['defult_currancy_symbol'] = '$';
        $post['CURRENCY_NAME'] = 'USD';
        $post['CURRENCY'] = '$';
    }

    $newCurrency = $post['defult_currancy'];
$currencyRate = CurrencyRate::where('store_id', $storeId)
    ->where('currency_code', $newCurrency)
    ->where('is_active', 1)
    ->first();

if (!$currencyRate) {
    return redirect()->back()->with(
        'error',
        __('Selected currency is not available for this store.')
    );
}

if ($newCurrency !== 'USD' && (float) $currencyRate->rate_per_usd <= 1) {
    return redirect()->back()->with(
        'error',
        __('Please set a valid exchange rate before changing the store currency.')
    );
}
    /*
     * إذا العملة تغيرت فعلاً
     * نحول القيم الحالية قبل حفظ العملة الجديدة.
     */
    if ($oldCurrency !== $newCurrency) {

        app(StoreCurrencyConversionService::class)
            ->convertStoreCurrentValues(
                $storeId,
                $oldCurrency,
                $newCurrency
            );
    }

    if (isset($post['site_currency_symbol_position'])) {

        $post['site_currency_symbol_position'] =
            !empty($request->site_currency_symbol_position)
                ? $request->site_currency_symbol_position
                : 'pre';
    }

    /*
     * حفظ إعدادات العملة الجديدة للمتجر
     */
    $settingQuery = Setting::query();

    foreach ($post as $key => $data) {

        (clone $settingQuery)->updateOrCreate(
            [
                'name' => $key,
                'store_id' => $storeId,
            ],
            [
                'value' => $data,
                'name' => $key,
                'store_id' => $storeId,
                'created_by' => auth()->user()->id,
            ]
        );
    }

    return redirect()->back()->with(
        'success',
        __('Currency Setting save successfully.')
    );
}

    public function updateNoteValue(Request $request)
    {
        $symbol_position = 'pre';
        $symbol = '$';
        $format = '1';
        $price  = '10000';
        $number = explode('.', $price);
        $length = strlen(trim($number[0]));
        $currency_symbol = explode('-', $request->defult_currancy);

        if ($length > 3) {

            $decimal_separator  = (isset($request->decimal_separator) && $request->decimal_separator == 'dot') ? '.' : ',';
            $thousand_separator = (isset($request->thousand_separator) && $request->thousand_separator == 'dot') ? '.' : ',';
        } else {
            $decimal_separator  = (isset($request->decimal_separator) &&  $request->decimal_separator == 'dot')  ? '.' : ',';
            $thousand_separator = (isset($request->thousand_separator) &&  $request->thousand_separator == 'dot') ? '.' : ',';
        }

        if (isset($request->site_currency_symbol_position) && $request->site_currency_symbol_position == "post") {
            $symbol_position = 'post';
        }

        if (isset($request->defult_currancy)) {
            $symbol = $request->defult_currancy;
        }

        if (isset($request->currency_format)) {
            $format = $request->currency_format;
        }
        if (isset($request->currency_space)) {
            $currency_space = isset($request->currency_space) ? $request->currency_space : '';
        }
        if (isset($request->site_currency_symbol_name)) {
            $symbol = $request->site_currency_symbol_name == 'symbol' ? $currency_symbol[0] : $currency_symbol[1];
        }
        $formatted_price = (
            ($symbol_position == "pre")  ?  $symbol : '') . (isset($currency_space) && $currency_space == 'withspace' ? ' ' : '')
            . number_format($price, $format, $decimal_separator, $thousand_separator) . (isset($currency_space) && $currency_space == 'withspace' ? ' ' : '') .
            (($symbol_position == "post") ?  $symbol : '');
        return response()->json(['success' => true, 'formatted_price' => $formatted_price]);
    }

    public function getEmailSettingFields(Request $request)
    {
        // P1-SEC-18 F5. Renders MAIL_PASSWORD into the form for whoever is logged in; see settingForm().
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return response()->json(['is_success' => false, 'message' => __('Permission denied.'), 'html' => ''], 403);
        }

        if (auth()->user()->type == 'super admin') {
            $setting = getSuperAdminAllSetting();
            $folder = 'SuperAdmin';
        } else {
            $setting = getAdminAllSetting();
        }
        $email_setting = $request->emailsetting;
        $returnHTML = view('setting.email_fields', compact('email_setting', 'setting'))->render();
        $response = [
            'is_success' => true,
            'message' => '',
            'html' => $returnHTML,
        ];
        return response()->json($response);
    }

  public function settingForm(Request $request)
    {
        try {
            $user = auth()->user();
            // P1-SEC-18 F5. This renders EVERY settings tab, the payment tab with every gateway key in it. The Settings menu entry
            // is hidden unless the user holds `Manage Setting`, but the route was open to anyone logged in -- including the
            // merchant's staff, whose getAdminAllSetting() resolves to their employer's settings. 403, never 401 (the shipped
            // app forces a logout on 401); the shape is the one the chat_gpt_setting refusal below already uses.
            if (! \App\Services\StoreSettingsAccess::permits($user)) {
                return response()->json(['is_success' => false, 'msg' => __('Permission denied.'), 'data' => ['content' => null]], 403);
            }
            $timezones = config('timezones');
            $setting = ($user->type == 'super admin') ? getSuperAdminAllSetting() : getAdminAllSetting();

            // Define the mapping of tab types to view names
            $views = [
                'email_setting' => 'setting.email_setting',
                'brand_setting' => 'setting.brand_setting',
                'system_setting' => 'setting.system_setting',
                'storage_setting' => 'setting.storage_setting',
                'payment_setting' => 'setting.payment_setting',
                'cookie_setting' => 'setting.cookie_setting',
                'recaptcha_setting' => 'setting.recaptcha_setting',
                'cache_setting' => 'setting.cache_setting',
                'style_setting' => 'setting.style_setting',
                'chat_gpt_setting' => 'setting.chatgpt_setting',
                'currency_setting' => 'setting.currency_setting',
                'email_notify_setting' => 'setting.email_notify_setting',
                'loyality_pro_setting' => 'setting.loyality_pro_setting',
                'shopify_setting' => 'setting.shopify_setting',
                'stock_setting' => 'setting.stock_setting',
                'twilio_setting' => 'setting.twilio_setting',
                'whatsapp_notify_setting' => 'setting.whatsapp_notify_setting',
                'whatsapp_setting' => 'setting.whatsapp_setting',
'otp_setting' => 'setting.otp_setting',
                'woocom_setting' => 'setting.woocom_setting',
                'webhook_setting' => 'setting.webhook_setting',
                'pwa_setting' => 'setting.pwa_setting',
                'pixel_field_setting' => 'setting.pixel_field_setting',
                'tax_opt_setting' => 'setting.tax_opt_setting',
                'whatsapp_msg_setting' => 'setting.whatsapp_msg_setting',
                'tax_opt_setting' => 'setting.tax_opt_setting',
                'refund_setting' => 'setting.refund_setting',
                'seo_setting' => 'setting.seo_setting'
            ];

            // Default tab type to 'email_setting' if not provided
            $tabType = $request->tab_type ?? 'email_setting';

            $plan = Plan::find($user->plan_id);
            $store_settings = getStoreById(getCurrentStore());

$storeId = getCurrentStore();

$currencyRates = CurrencyRate::where('store_id', $storeId)
    ->orderBy('currency_code')
    ->get();

if ($currencyRates->isEmpty()) {

    $defaultCurrencies = [
        ['USD', 'US Dollar', '$'],
        ['EUR', 'Euro', '€'],

        ['SYP', 'Syrian Pound', 'ل.س'],
        ['LBP', 'Lebanese Pound', 'ل.ل'],
        ['IQD', 'Iraqi Dinar', 'د.ع'],
        ['EGP', 'Egyptian Pound', 'ج.م'],
        ['JOD', 'Jordanian Dinar', 'د.أ'],

        ['SAR', 'Saudi Riyal', 'ر.س'],
        ['AED', 'UAE Dirham', 'د.إ'],
        ['KWD', 'Kuwaiti Dinar', 'د.ك'],
        ['QAR', 'Qatari Riyal', 'ر.ق'],
        ['BHD', 'Bahraini Dinar', 'د.ب'],
        ['OMR', 'Omani Rial', 'ر.ع'],
    ];

    foreach ($defaultCurrencies as $currency) {

        CurrencyRate::create([
            'store_id' => $storeId,
            'currency_code' => $currency[0],
            'currency_name' => $currency[1],
            'symbol' => $currency[2],
            'rate_per_usd' => 1,
            'is_active' => 1,
            'created_by' => auth()->id(),
        ]);
    }

    $currencyRates = CurrencyRate::where('store_id', $storeId)
        ->orderBy('currency_code')
        ->get();
}

$currencyRates = CurrencyRate::where('store_id', getCurrentStore())
    ->orderBy('currency_code')
    ->get();

            if ($tabType == 'email_setting') {
                $email_setting = EmailTemplate::$email_settings;
                $get_setting = $setting['email_setting'] ?? 'smtp';
                $view = view($views[$tabType], compact('get_setting', 'email_setting', 'setting'));
            } elseif ($tabType == 'storage_setting') {
                $file_type = config('files_types');
                $view = view($views[$tabType], compact('setting', 'file_type'));
            } elseif ($tabType == 'pwa_setting') {
                try {
                    $pwa_data = \File::get(storage_path('uploads/customer_app/store_' . $store_settings->id . '/manifest.json'));
                    $pwa_data = json_decode($pwa_data);
                } catch (\Throwable $th) {
                    $pwa_data = '';
                }
                $view = view($views[$tabType], compact('setting', 'pwa_data', 'store_settings'));
            } elseif ($tabType == 'pixel_field_setting') {
                $PixelFields = PixelFields::where('store_id', getCurrentStore())->get();
                $view = view($views[$tabType], compact('setting', 'PixelFields'));
            } elseif ($tabType == 'webhook_setting') {
                $webhooks = Webhook::where('store_id', getCurrentStore())->get();
                $view = view($views[$tabType], compact('setting', 'webhooks'));
            } elseif ($tabType == 'chat_gpt_setting') {
                // P1-SEC-19. These are the PLATFORM's OpenAI keys: the model has no tenant column, and
                // AITemplateController hands one to whichever tenant asks. The tab link is shown to a
                // platform operator only and ChatgptSettings() (the save) refuses everyone else, but
                // this read had no check, so any logged-in merchant or staff member who POSTed the tab
                // name was given every key. Operator first, per CLAUDE.md: the type string is the only
                // operator marker. 403, never 401 (the shipped Flutter app logs out on 401).
                if (strtolower(trim((string) $user->type)) !== 'super admin') {
                    return response()->json([
                        'is_success' => false,
                        'msg' => __('Permission denied.'),
                        'data' => ['content' => null],
                    ], 403);
                }

                $ai_key_settings = ApikeySetiings::get();
                $view = view($views[$tabType], compact('setting', 'ai_key_settings'));
            } elseif ($tabType == 'tax_opt_setting') {
                // $emailTemplates = EmailTemplate::all();
                $taxes = Tax::where('store_id', getCurrentStore())
                    ->pluck('name', 'id')
                    ->prepend('Shipping tax based on cart items', '');

                $tax_option = TaxOption::where('created_by', $user->id)
                    ->where('store_id', getCurrentStore())
                    ->pluck('value', 'name')->toArray();
                $view = view($views[$tabType], compact('setting', 'tax_option', 'taxes'));
            } 
            
            
            
            
             elseif ($tabType == 'currency_setting') {

    $view = view(
        $views[$tabType],
        compact(
            'setting',
            'currencyRates'
        )
    );}
            
            
            
            
            elseif ($tabType == 'whatsapp_notify_setting') {
                $WhatsappNotification = WhatsappMessage::where('store_id', getCurrentStore())
                    ->get();
                $view = view($views[$tabType], compact('setting', 'WhatsappNotification'));
            } elseif ($tabType == 'payment_setting') {
                // The admin payment screen. Two things happen here, both routed through the
                // one retired-gateway predicate (RetiredGateways::PROVIDERS):
                //  1. the gateway's displayed name carries the reason, so the screen explains
                //     itself instead of silently ignoring the operator;
                //  2. $setting is normalised so a retired gateway's enable TOGGLE renders off.
                //     Only the enable value is overridden -- every stored credential keeps its
                //     value and stays visible in its field, and the settings block is not
                //     deleted. The operator's data is the owner's data.
                // The toggle is still a real form control; it is made ineffective on the SAVE
                // side by PaymentSettingController::processGateway(), not by hiding it.
                $paymentGateways = $this->annotateRetiredGateways($this->paymentSettingArray());
                $setting = RetiredGateways::disableIn($setting);
                $view = view($views[$tabType], compact('setting', 'paymentGateways'));
            } elseif ($tabType == 'email_notify_setting') {
                $s_admin = User::where('type', 'super admin')->first();
                if ($s_admin) {
                    $emailTemplates = EmailTemplate::where('created_by', $s_admin->id)->get();
                } else {
                    $emailTemplates = EmailTemplate::whereNot('created_by', \Auth::user()->id)->get();
                }
                $view = view($views[$tabType], compact('emailTemplates', 'setting'));
            } 
            
elseif ($tabType == 'otp_setting') {

    $storeId = getCurrentStore();

    $otpIntegration = OtpIntegration::where(
        'store_id',
        $storeId
    )->first();

    $view = view(
        $views[$tabType],
        compact(
            'setting',
            'otpIntegration'
        )
    );
}
            
            else {
                $view = view($views[$tabType] ?? $views['email_setting'], compact('setting', 'timezones', 'plan'));
            }

            

            // Render the HTML view
            $html = $view->render();

            // Return JSON response
            return response()->json([
                'is_success' => true,
                "msg" => ucfirst(str_replace('_', ' ', $tabType)) . " form get successfully.",
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

    public function SEOSetting(Request $request)
    {

        // P1-SEC-27. This handler writes EVERY posted name into `settings` for the caller's store, and its form is on the Settings
        // screen, which settingForm() already refuses to anyone without `Manage Setting`; the route itself was open to every logged-in
        // user of the store, so the write asks for it too (App\Services\StoreSettingsAccess).
        if (! \App\Services\StoreSettingsAccess::permits(auth()->user())) {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        session()->put(['setting_tab' => 'seo_setting']);
        $post = $request->all();
        unset($post['_token']);
        $dir        = 'uploads';
        if ($request->hasFile('metaimage')) {
            $fileName = rand(10, 100) . '_' . time() . "_" . $request->metaimage->getClientOriginalName();
            $path = Utility::upload_file($request, 'metaimage', $fileName, $dir, []);
            if ($path['flag'] == '0') {
                return redirect()->back()->with('error', $path['msg']);
            }
        }
        if (!empty($request->metaimage) && isset($path['url'])) {
            $image = str_replace('/storage', '', $path['url']);
        }
        $settingQuery = Setting::query();
        foreach ($post as $key => $data) {
            if ($key == 'metaimage' && isset($path['url'])) {
                (clone $settingQuery)->updateOrCreate(
                    [
                        'name' => 'metaimage',

                        'store_id' => getCurrentStore()
                    ],
                    [
                        'value'         => $path['url'] ?? null,
                        'name'          => 'metaimage',
                        
                        'store_id'      => getCurrentStore(),
                        'created_by'    => auth()->user()->id,
                    ]
                );
            } else {
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
        }

        return redirect()->back()->with('success', 'SEO successfully updated.');
    }

    /**
     * Label every retired gateway with the reason it cannot be enabled.
     *
     * Done here, over the finished array, rather than by editing the two entries: the
     * retired list lives in exactly one place (RetiredGateways::PROVIDERS) and retiring a
     * third provider must not require remembering this screen.
     */
    private function annotateRetiredGateways($gateways)
    {
        if (!is_array($gateways)) {
            return $gateways;
        }
        foreach ($gateways as $index => $gateway) {
            if (!isset($gateway['id']) || !RetiredGateways::isRetired($gateway['id'])) {
                continue;
            }
            $gateways[$index]['retired'] = true;
            $gateways[$index]['name'] = $gateway['name'] . ' (' . __('retired - no longer available') . ')';
        }

        return $gateways;
    }

    private function paymentSettingArray()
    {
        return [
            [
                'id' => 'COD',
                'name' => __('COD'),
                'enable_key' => 'is_cod_enabled',
                'description_key' => 'cod_info',
                'description_default' => __('Cash on Delivery'),
                'image_key' => 'cod_image',
                'is_only_admin' => true,
                'image_default' => 'uploads/payment/cod.png',
            ],
            [
                'id' => 'Bank_transfer',
                'name' => __('Bank Transfer'),
                'enable_key' => 'is_bank_transfer_enabled',
                'description_key' => 'bank_transfer',
                'description_default' => __('Bank Transfer add bank details here'),
                'image_key' => 'bank_transfer_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/bank.png',
            ],
            [
                'id' => 'Stripe',
                'name' => __('Stripe'),
                'enable_key' => 'is_stripe_enabled',
                'description_key' => 'stripe_unfo',
                'image_key' => 'stripe_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/stripe.png',
                'fields' => [
                    ['key' => 'stripe_publishable_key', 'label' => __('Stripe Publishable Key')],
                    ['key' => 'stripe_secret_key', 'label' => __('Stripe Secret Key')],
                ],
            ],



    [
                'id' => 'Paymera',
                'name' => __('Paymera'),
                'enable_key' => 'is_Paymera_enabled',
                'description_key' => 'Paymera_unfo',
                'image_key' => 'Paymera_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/paymera.png',
                'fields' => [
                    ['key' => 'PAYMERA_TERMINAL_ID', 'label' => __('PAYMERA_TERMINAL_ID')],
                    ['key' => 'PAYMERA_USERNAME', 'label' => __('PAYMERA_USERNAME')],
                ],
            ],



            [
                'id' => 'paystack',
                'name' => __('Paystack'),
                'enable_key' => 'is_paystack_enabled',
                'description_key' => 'paystack_unfo',
                'image_key' => 'paystack_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/paystack.png',
                'fields' => [
                    ['key' => 'paystack_public_key', 'label' => __('Paystack Public Key')],
                    ['key' => 'paystack_secret_key', 'label' => __('Paystack Secret Key')],
                ],
            ],
            [
                'id' => 'razorpay',
                'name' => __('Razorpay'),
                'enable_key' => 'is_razorpay_enabled',
                'description_key' => 'razorpay_unfo',
                'image_key' => 'razorpay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/razorpay.png',
                'fields' => [
                    ['key' => 'razorpay_public_key', 'label' => __('Razorpay Public Key')],
                    ['key' => 'razorpay_secret_key', 'label' => __('Razorpay Secret Key')],
                ],
            ],
            [
                'id' => 'mercado',
                'name' => __('Mercado Pago'),
                'enable_key' => 'is_mercado_enabled',
                'description_key' => 'mercado_unfo',
                'image_key' => 'mercado_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/mercado.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'mercado_access_token', 'label' => __('Mercado Access Token')],
                ],
            ],
            [
                'id' => 'skrill',
                'name' => __('Skrill'),
                'enable_key' => 'is_skrill_enabled',
                'description_key' => 'skrill_unfo',
                'image_key' => 'skrill_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/skrill.png',
                'fields' => [
                    ['key' => 'skrill_email', 'label' => __('Skrill Email')],
                ],
            ],
            [
                'id' => 'paymentwall',
                'name' => __('PaymentWall'),
                'enable_key' => 'is_paymentwall_enabled',
                'description_key' => 'paymentwall_unfo',
                'image_key' => 'paymentwall_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/paymentwall.png',
                'fields' => [
                    ['key' => 'paymentwall_public_key', 'label' => __('PaymentWall Public Key')],
                    ['key' => 'paymentwall_private_key', 'label' => __('PaymentWall Private Key')],
                ],
            ],
            [
                'id' => 'paypal',
                'name' => __('Paypal'),
                'enable_key' => 'is_paypal_enabled',
                'description_key' => 'paypal_unfo',
                'image_key' => 'paypal_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/paypal.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'paypal_client_id', 'label' => __('Paypal Client Key')],
                    ['key' => 'paypal_secret_key', 'label' => __('Paypal Secret Key')],
                ],
            ],
            [
                'id' => 'flutterwave',
                'name' => __('Flutterwave'),
                'enable_key' => 'is_flutterwave_enabled',
                'description_key' => 'flutterwave_unfo',
                'image_key' => 'flutterwave_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/flutterwave.png',
                'fields' => [
                    ['key' => 'flutterwave_public_key', 'label' => __('Flutterwave Public Key')],
                    ['key' => 'flutterwave_secret_key', 'label' => __('Flutterwave Secret Key')],
                ],
            ],
            [
                'id' => 'paytm',
                'name' => __('Paytm'),
                'enable_key' => 'is_paytm_enabled',
                'description_key' => 'paytm_unfo',
                'image_key' => 'paytm_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/paytm.png',
                'mode_options' => ['local' => __('Local'), 'production' => __('Production')],
                'fields' => [
                    ['key' => 'paytm_merchant_id', 'label' => __('Merchant ID')],
                    ['key' => 'paytm_merchant_key', 'label' => __('Merchant Key')],
                    ['key' => 'paytm_industry_type', 'label' => __('Industry Type')],
                ],
            ],
            [
                'id' => 'mollie',
                'name' => __('Mollie'),
                'enable_key' => 'is_mollie_enabled',
                'description_key' => 'mollie_unfo',
                'image_key' => 'mollie_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/mollie.png',
                'fields' => [
                    ['key' => 'mollie_api_key', 'label' => __('Mollie Api Key')],
                    ['key' => 'mollie_profile_id', 'label' => __('Mollie Profile ID')],
                    ['key' => 'mollie_partner_id', 'label' => __('Mollie Partner ID')],
                ],
            ],
            [
                'id' => 'coingate',
                'name' => __('Coingate'),
                'enable_key' => 'is_coingate_enabled',
                'description_key' => 'coingate_unfo',
                'image_key' => 'coingate_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/coingate.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'coingate_auth_token', 'label' => __('CoinGate Auth Token')],
                ],
            ],
            [
                'id' => 'sspay',
                'name' => __('Sspay'),
                'enable_key' => 'is_sspay_enabled',
                'description_key' => 'sspay_unfo',
                'image_key' => 'sspay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/sspay.png',
                'fields' => [
                    ['key' => 'sspay_secret_key', 'label' => __('Secret Key')],
                    ['key' => 'sspay_category_code', 'label' => __('Category Code')],
                ],
            ],
            [
                'id' => 'toyyibpay',
                'name' => __('Toyyibpay'),
                'enable_key' => 'is_toyyibpay_enabled',
                'description_key' => 'toyyibpay_unfo',
                'image_key' => 'toyyibpay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/toyyibpay.png',
                'fields' => [
                    ['key' => 'toyyibpay_secret_key', 'label' => __('Secret Key')],
                    ['key' => 'toyyibpay_category_code', 'label' => __('Category Code')],
                ],
            ],
            [
                'id' => 'paytabs',
                'name' => __('Paytab'),
                'enable_key' => 'is_paytabs_enabled',
                'description_key' => 'paytabs_unfo',
                'image_key' => 'paytabs_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/paytabs.png',
                'fields' => [
                    ['key' => 'paytabs_profile_id', 'label' => __('Profile ID')],
                    ['key' => 'paytabs_server_key', 'label' => __('Paytab Server Key')],
                    ['key' => 'paytabs_region', 'label' => __('Paytab Region')],
                ],
            ],                        
            [
                'id' => 'iyzipay',
                'name' => __('IyziPay'),
                'enable_key' => 'is_iyzipay_enabled',
                'description_key' => 'iyzipay_unfo',
                'image_key' => 'iyzipay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/iyzipay.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'iyzipay_private_key', 'label' => __('Private Key')],
                    ['key' => 'iyzipay_secret_key', 'label' => __('Secret Key')],
                ],
            ],
            [
                'id' => 'payfast',
                'name' => __('PayFast'),
                'enable_key' => 'is_payfast_enabled',
                'description_key' => 'payfast_unfo',
                'image_key' => 'payfast_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/payfast.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'payfast_merchant_id', 'label' => __('Merchant ID')],
                    ['key' => 'payfast_merchant_key', 'label' => __('Merchant Key')],
                    ['key' => 'payfast_salt_passphrase', 'label' => __('Salt Passphrase')],
                ],
            ],
            [
                'id' => 'benefit',
                'name' => __('Benefit'),
                'enable_key' => 'is_benefit_enabled',
                'description_key' => 'benefit_unfo',
                'image_key' => 'benefit_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/benefit.png',
                'fields' => [
                    ['key' => 'benefit_private_key', 'label' => __('Benefit Key')],
                    ['key' => 'benefit_secret_key', 'label' => __('Benefit Secret Key')],
                ],
            ],
            [
                'id' => 'cashfree',
                'name' => __('Cashfree'),
                'enable_key' => 'is_cashfree_enabled',
                'description_key' => 'cashfree_unfo',
                'image_key' => 'cashfree_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/cashfree.png',
                'fields' => [
                    ['key' => 'cashfree_key', 'label' => __('Cashfree Key')],
                    ['key' => 'cashfree_secret_key', 'label' => __('Cashfree Secret Key')],
                ],
            ],
            [
                'id' => 'aamarpay',
                'name' => __('Aamarpay'),
                'enable_key' => 'is_aamarpay_enabled',
                'description_key' => 'aamarpay_unfo',
                'image_key' => 'aamarpay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/aamarpay.png',
                'fields' => [
                    ['key' => 'aamarpay_store_id', 'label' => __('Store ID')],
                    ['key' => 'aamarpay_signature_key', 'label' => __('Signature Key')],
                    ['key' => 'aamarpay_description', 'label' => __('Aamarpay Description')],
                ],
            ],
            [
                'id' => 'telegram',
                'name' => __('Telegram'),
                'enable_key' => 'is_telegram_enabled',
                'description_key' => 'telegram_unfo',
                'image_key' => 'telegram_image',
                'is_only_admin' => true,
                'image_default' => 'uploads/payment/telegram.png',
                'fields' => [
                    ['key' => 'telegram_access_token', 'label' => __('Telegram Access Token')],
                    ['key' => 'telegram_chat_id', 'label' => __('Telegram Chat ID')],
                ],
            ],
            [
                'id' => 'whatsapp',
                'name' => __('Whatsapp'),
                'enable_key' => 'is_whatsapp_enabled',
                'description_key' => 'whatsapp_unfo',
                'image_key' => 'whatsapp_image',
                'is_only_admin' => true,
                'image_default' => 'uploads/payment/whatsapp.png',
                'fields' => [
                    ['key' => 'whatsapp_number', 'label' => __('Whatsapp Number')],
                ],
            ],
            [
                'id' => 'paytr',
                'name' => __('Pay TR'),
                'enable_key' => 'is_paytr_enabled',
                'description_key' => 'paytr_unfo',
                'image_key' => 'paytr_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/paytr.png',
                'fields' => [
                    ['key' => 'paytr_merchant_id', 'label' => __('Merchant ID')],
                    ['key' => 'paytr_merchant_key', 'label' => __('Merchant Key')],
                    ['key' => 'paytr_salt_key', 'label' => __('Salt Passphrase')],
                ],
            ],
            // RETIRED (RetiredGateways::PROVIDERS). Entry KEPT on purpose: deleting it would
            // orphan the stored credentials. annotateRetiredGateways() labels it and
            // RetiredGateways::disableIn() renders its toggle off.
            [
                'id' => 'yookassa',
                'name' => __('Yookassa'),
                'enable_key' => 'is_yookassa_enabled',
                'description_key' => 'yookassa_unfo',
                'image_key' => 'yookassa_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/yookassa.png',
                'fields' => [
                    ['key' => 'yookassa_shop_id_key', 'label' => __('Shop ID Key')],
                    ['key' => 'yookassa_secret_key', 'label' => __('Secret Key')],
                ],
            ],
            [
                'id' => 'Xendit',
                'name' => __('Xendit'),
                'enable_key' => 'is_Xendit_enabled',
                'description_key' => 'Xendit_unfo',
                'image_key' => 'Xendit_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/xendit.png',
                'fields' => [
                    ['key' => 'Xendit_api_key', 'label' => __('Xendit Api Key')],
                    ['key' => 'Xendit_token_key', 'label' => __('Xendit Token Key')],
                ],
            ],
            [
                'id' => 'midtrans',
                'name' => __('Midtrans'),
                'enable_key' => 'is_midtrans_enabled',
                'description_key' => 'midtrans_unfo',
                'image_key' => 'midtrans_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/midtrans.png',
                'fields' => [
                    ['key' => 'midtrans_secret_key', 'label' => __('Secret Key')],
                ],
            ],
            [
                'id' => 'nepalste',
                'name' => __('Nepalste'),
                'enable_key' => 'is_nepalste_enabled',
                'description_key' => 'nepalste_unfo',
                'image_key' => 'nepalste_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/nepalste.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'nepalste_public_key', 'label' => __('Public Key')],
                    ['key' => 'nepalste_secret_key', 'label' => __('Secret Key')],
                ],
            ],
            // RETIRED (RetiredGateways::PROVIDERS). Entry KEPT -- see the note on 'yookassa'.
            [
                'id' => 'payhere',
                'name' => __('PayHere'),
                'enable_key' => 'is_payhere_enabled',
                'description_key' => 'payhere_unfo',
                'image_key' => 'payhere_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/payhere.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'payhere_merchant_id', 'label' => __('Merchant ID')],
                    ['key' => 'payhere_merchant_secret', 'label' => __('Merchant Secret')],
                    ['key' => 'payhere_app_id', 'label' => __('App ID')],
                    ['key' => 'payhere_app_secret', 'label' => __('App Secret')],
                ],
            ],
            [
                'id' => 'khalti',
                'name' => __('Khalti'),
                'enable_key' => 'is_khalti_enabled',
                'description_key' => 'khalti_unfo',
                'image_key' => 'khalti_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/khalti.png',
                'fields' => [
                    ['key' => 'khalti_public_key', 'label' => __('Public Key')],
                    ['key' => 'khalti_secret_key', 'label' => __('Secret Key')],
                ],
            ],
            [
                'id' => 'authorizenet',
                'name' => __('AuthorizeNet'),
                'enable_key' => 'is_authorizenet_enabled',
                'description_key' => 'authorizenet_unfo',
                'image_key' => 'authorizenet_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/authorizenet.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'authorizenet_login_id', 'label' => __('Merchant Login ID')],
                    ['key' => 'authorizenet_transaction_key', 'label' => __('Merchant Transaction Key')],
                ],
            ],
            [
                'id' => 'tap',
                'name' => __('Tap'),
                'enable_key' => 'is_tap_enabled',
                'description_key' => 'tap_unfo',
                'image_key' => 'tap_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/tap.png',
                'fields' => [
                    ['key' => 'tap_secret_key', 'label' => __('Secret Key')],
                ],
            ],
            [
                'id' => 'phonepe',
                'name' => __('PhonePe'),
                'enable_key' => 'is_phonepe_enabled',
                'description_key' => 'phonepe_unfo',
                'image_key' => 'phonepe_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/phonepe.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'phonepe_client_id', 'label' => __('Client ID')],
                    ['key' => 'phonepe_client_secret', 'label' => __('Client Secret')],
                    ['key' => 'phonepe_client_version', 'label' => __('Version')],
                ],
            ],
            [
                'id' => 'paddle',
                'name' => __('Paddle'),
                'enable_key' => 'is_paddle_enabled',
                'description_key' => 'paddle_unfo',
                'image_key' => 'paddle_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/paddle.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'paddle_vendor_id', 'label' => __('Vendor ID')],
                    ['key' => 'paddle_vendor_auth_code', 'label' => __('Vendor Auth Code')],
                    ['key' => 'paddle_public_key', 'label' => __('Public Key')],
                ],
            ],
            [
                'id' => 'paiementpro',
                'name' => __('Paiement Pro'),
                'enable_key' => 'is_paiementpro_enabled',
                'description_key' => 'paiementpro_unfo',
                'image_key' => 'paiementpro_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/paiementpro.png',
                'fields' => [
                    ['key' => 'paiementpro_merchant_id', 'label' => __('Merchant ID')],
                ],
            ],
            [
                'id' => 'fedpay',
                'name' => __('FedaPay'),
                'enable_key' => 'is_fedpay_enabled',
                'description_key' => 'fedpay_unfo',
                'image_key' => 'fedpay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/fedpay.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'fedpay_public_key', 'label' => __('Public Key')],
                    ['key' => 'fedpay_secret_key', 'label' => __('Secret Key')],
                ],
            ],
            [
                'id' => 'cinetpay',
                'name' => __('CinetPay'),
                'enable_key' => 'is_cinetpay_enabled',
                'description_key' => 'cinet_pay_unfo',
                'image_key' => 'cinet_pay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/cinet.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'cinet_pay_site_id', 'label' => __('CinetPay Site ID')],
                    ['key' => 'cinet_pay_api_key', 'label' => __('CinetPay Api Key')],
                    ['key' => 'cinet_pay_secret_key', 'label' => __('CinetPay Secret Key')],
                ],
            ],
            [
                'id' => 'Senangpay',
                'name' => __('SenangPay'),
                'enable_key' => 'is_Senangpay_enabled',
                'description_key' => 'senang_pay_unfo',
                'image_key' => 'senang_pay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/senang.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'senang_pay_merchant_id', 'label' => __('SenangPay Merchant ID')],
                    ['key' => 'senang_pay_secret_key', 'label' => __('SenangPay Secret Key')],
                ],
            ],
            [
                'id' => 'cybersource',
                'name' => __('CyberSource'),
                'enable_key' => 'is_cybersource_enabled',
                'description_key' => 'cybersource_pay_unfo',
                'image_key' => 'cybersource_pay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/cybersource.png',
                'fields' => [
                    ['key' => 'cybersource_pay_merchant_id', 'label' => __('CyberSource Merchant ID')],
                    ['key' => 'cybersource_pay_secret_key', 'label' => __('CyberSource Secret Key')],
                    ['key' => 'cybersource_pay_api_key', 'label' => __('CyberSource Api Key')],
                ],
            ],
            [
                'id' => 'ozow',
                'name' => __('Ozow'),
                'enable_key' => 'is_ozow_enabled',
                'description_key' => 'ozow_pay_unfo',
                'image_key' => 'ozow_pay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/ozow.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'ozow_pay_Site_key', 'label' => __('Ozow Site Key')],
                    ['key' => 'ozow_pay_private_key', 'label' => __('Ozow Private Key')],
                    ['key' => 'ozow_pay_api_key', 'label' => __('Ozow Api Key')],
                ],
            ],
            [
                'id' => 'myfatoorah',
                'name' => __('MyFatoorah'),
                'enable_key' => 'is_myfatoorah_enabled',
                'description_key' => 'myfatoorah_pay_unfo',
                'image_key' => 'myfatoorah_pay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/myfatoorah.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'myfatoorah_pay_api_key', 'label' => __('MyFatoorah Api Key')],
                    ['key' => 'myfatoorah_pay_country_iso', 'label' => __('MyFatoorah Country ISO')],
                ],
            ],
            [
                'id' => 'easebuzz',
                'name' => __('Easebuzz'),
                'enable_key' => 'is_easebuzz_enabled',
                'description_key' => 'easebuzz_unfo',
                'image_key' => 'easebuzz_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/easebuzz.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'easebuzz_merchant_key', 'label' => __('Easebuzz Merchant Key')],
                    ['key' => 'easebuzz_salt_key', 'label' => __('Easebuzz Salt Key')],
                    ['key' => 'easebuzz_enviroment_name', 'label' => __('Easebuzz Enviroment Name')],
                ],
            ],
            [
                'id' => 'nmi',
                'name' => __('NMI'),
                'enable_key' => 'is_nmi_enabled',
                'description_key' => 'nmi_unfo',
                'image_key' => 'nmi_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/nmi.png',
                'fields' => [
                    ['key' => 'nmi_api_private_key', 'label' => __('NMI Api Private Key')],
                ],
            ],
            [
                'id' => 'payu',
                'name' => __('PayU'),
                'enable_key' => 'is_payu_enabled',
                'description_key' => 'payu_unfo',
                'image_key' => 'payu_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/payu.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'payu_merchant_key', 'label' => __('Merchant Key')],
                    ['key' => 'payu_salt_key', 'label' => __('Salt Key')],
                ],
            ],
            [
                'id' => 'paynow',
                'name' => __('Paynow'),
                'enable_key' => 'is_paynow_enabled',
                'description_key' => 'paynow_pay_unfo',
                'image_key' => 'paynow_pay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/paynow.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'paynow_pay_integration_id', 'label' => __('Paynow Integration ID')],
                    ['key' => 'paynow_pay_integration_key', 'label' => __('Paynow Integration Key')],
                    ['key' => 'paynow_pay_merchant_email', 'label' => __('Paynow Merchant Email')],
                ],
            ],
            [
                'id' => 'sofort',
                'name' => __('Sofort'),
                'enable_key' => 'is_sofort_enabled',
                'description_key' => 'sofort_unfo',
                'image_key' => 'sofort_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/sofort.png',
                'fields' => [
                    ['key' => 'sofort_publishable_key', 'label' => __('Sofort Publishable Key')],
                    ['key' => 'sofort_secret_key', 'label' => __('Sofort Secret Key')],
                ],
            ],
            [
                'id' => 'esewa',
                'name' => __('ESewa'),
                'enable_key' => 'is_esewa_enabled',
                'description_key' => 'esewa_unfo',
                'image_key' => 'esewa_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/esewa.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'esewa_merchant_key', 'label' => __('ESewa Merchant ID')],
                ],
            ],
            [
                'id' => 'dpopay',
                'name' => __('DPO Pay'),
                'enable_key' => 'is_dpopay_enabled',
                'description_key' => 'dpo_pay_unfo',
                'image_key' => 'dpo_pay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/dpo.png',
                'fields' => [
                    ['key' => 'dpo_pay_Company_Token', 'label' => __('Company Token')],
                    ['key' => 'dpo_pay_Service_Type', 'label' => __('Service Type')],
                ],
            ],
            [
                'id' => 'braintree',
                'name' => __('Braintree'),
                'enable_key' => 'is_braintree_enabled',
                'description_key' => 'braintree_pay_unfo',
                'image_key' => 'braintree_pay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/braintree.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'braintree_pay_merchant_id', 'label' => __('Braintree Merchant ID')],
                    ['key' => 'braintree_pay_public_key', 'label' => __('Braintree Public Key')],
                    ['key' => 'braintree_pay_private_key', 'label' => __('Braintree Private Key')],
                ],
            ],
            [
                'id' => 'powertranz',
                'name' => __('PowerTranz'),
                'enable_key' => 'is_powertranz_enabled',
                'description_key' => 'powertranz_pay_unfo',
                'image_key' => 'powertranz_pay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/powertranz.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'powertranz_pay_merchant_id', 'label' => __('PowerTranz Merchant ID')],
                    ['key' => 'powertranz_pay_processing_password', 'label' => __('PowerTranz Processing Password')],
                    ['key' => 'powertranz_pay_production_url', 'label' => __('PowerTranz Production url')],
                ],
            ],
            [
                'id' => 'sslcommerz',
                'name' => __('SSLCommerz'),
                'enable_key' => 'is_sslcommerz_enabled',
                'description_key' => 'sslcommerz_pay_unfo',
                'image_key' => 'sslcommerz_pay_image',
                'is_only_admin' => false,
                'image_default' => 'uploads/payment/sslcommerz.png',
                'mode_options' => ['sandbox' => __('Sandbox'), 'live' => __('Live')],
                'fields' => [
                    ['key' => 'sslcommerz_pay_store_id', 'label' => __('SSLCommerz Store ID')],
                    ['key' => 'sslcommerz_pay_secret_key', 'label' => __('SSLCommerz Secret Key')],
                ],
            ]
        ];
    }
}