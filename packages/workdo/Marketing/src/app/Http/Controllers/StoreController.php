<?php

namespace Workdo\Marketing\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use JeroenDesloovere\VCard\VCard;
use Illuminate\Support\Facades\Auth;
use App\Models\Store;
use Illuminate\Support\Facades\Storage;
use App\Models\Setting;
use Illuminate\Validation\Rules;
use App\Models\Utility;
use Session;
use Workdo\Marketing\app\Models\Store as VcardStore;
use Workdo\Marketing\app\Models\Storefield;
use Workdo\Marketing\app\Models\Appoinment;
use Workdo\Marketing\app\Models\ContactInfo;
use Workdo\Marketing\app\Models\Service;
use Workdo\Marketing\app\Models\Testimonial;
use Workdo\Marketing\app\Models\Social;
use Workdo\Marketing\app\Models\CardProduct;
use Workdo\Marketing\app\Models\CardPayment;
use Workdo\Marketing\app\Models\Gallery;
use Workdo\Marketing\app\Models\PixelFields;
use Workdo\Marketing\app\Models\Storeqr;
use Workdo\Marketing\app\Models\StoreHours as BusinessHours;
use Illuminate\Support\Facades\Cache;

class StoreController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {

        $company_setting = getAdminAllSetting();
        $store = getStoreById(getCurrentStore());
        $themeOne = VcardStore::themeOne();
        $services = Service::cardServiceData($store->id);
        $services_content = [];

        if (!empty($services->content)) {
            $services_content = json_decode($services->content);
        }

        $testimonials = Testimonial::cardTestimonialData($store->id);
        $testimonials_content = [];
        if (!empty($testimonials->content)) {
            $testimonials_content = json_decode($testimonials->content);
        }

        $sociallinks = Social::cardSocialData($store->id);
        $social_content = [];
        if (!empty($sociallinks->content)) {
            $social_content = json_decode($sociallinks->content);
        }

        $days = BusinessHours::$days;
        $businesshours = BusinessHours::cardBusinessHour($store->id);
        $business_hours = [];
        if (!empty($businesshours->content)) {
            $business_hours = json_decode($businesshours->content);
        }

        $appoinment = Appoinment::cardAppointmentData($store->id);
        $appoinment_hours = [];

        if (!empty($appoinment->content)) {
            $appoinment_hours = json_decode($appoinment->content);
        }

        $gallaryoption = Gallery::$gallaryOption;
        $gallery = Gallery::cardGalleryData($store->id);
        $gallery_contents = [];
        if (!empty($gallery->content)) {
            $gallery_contents = json_decode($gallery->content);
        }

        $products = CardProduct::where('store_id', $store->id)->first();
        $products_content = [];
        if (!empty($products->content)) {
            $products_content = json_decode($products->content);
        }

        $custom_html = [];
        if (!empty($company_setting->custom_html_text)) {
            $custom_html = json_decode($company_setting->custom_html_text);
        }

        $branding = [];
        if (!empty($store->branding_text)) {
            $branding = $store->branding_text;
        }

        $storageSettings=getSuperAdminAllSetting();
        $mp4_msg = null;
        $mimes = !empty($storageSettings['local_storage_validation']) ? $storageSettings['local_storage_validation'] : 'jpeg,jpg,png,svg,zip,txt,gif,docx';
        if (strpos($mimes, 'mp4') !== false) {
            $mp4_msg = '';
        } else {
            $mp4_msg = __('You can`t upload mp4 video because superadmin has not allowed it in storage settings.');
        }

        $app_url = trim(env('APP_URL'), '/');
        $business_url = $app_url . '/cards/' . $store['slug'];


        $serverName = str_replace(
            [
                'http://',
                'https://',
            ],
            '',
            env('APP_URL')
        );

        $serverIp = gethostbyname($serverName);
        if ($serverIp == $_SERVER['SERVER_ADDR']) {
            $serverIp;
        } else {
            $serverIp = request()->server('SERVER_ADDR');
        }

        if (!empty($store->enable_subdomain) && $store->enable_subdomain == 'on') {
            // Remove the http://, www., and slash(/) from the URL
            $input = env('APP_URL');

            // If URI is like, eg. www.way2tutorial.com/
            $input = trim($input, '/');
            // If not have http:// or https:// then prepend it
            if (!preg_match('#^http(s)?://#', $input)) {
                $input = 'http://' . $input;
            }

            $urlParts = parse_url($input);

            // Remove www.
            $subdomain_name = preg_replace('/^www\./', '', $urlParts['host']);
            // Output way2tutorial.com
        } else {
            $subdomain_name = str_replace(
                [
                    'http://',
                    'https://',
                ],
                '',
                env('APP_URL')
            );
        }

        $cardPayment = CardPayment::cardPaymentData($store->id);
        $cardPayment_content = [];
        if (!empty($cardPayment->content)) {
            $cardPayment_content = json_decode($cardPayment->content);
        }

        $subdomain_Ip = '';
        $subdomainPointing = '';
        $domainip = '';
        $domainPointing = '';

        $serverIp = gethostbyname($serverName);

        if ($serverIp == $_SERVER['SERVER_ADDR']) {
            $serverIp;
        } else {
            $serverIp = request()->server('SERVER_ADDR');
        }



        try {
            $manifestPath = 'public/theme_app/business_' . $store->id . '/manifest.json';

            if (Storage::exists($manifestPath)) {
                $pwa_data = Storage::get($manifestPath);
                $pwa_data = json_decode($pwa_data);

            } else {
                $pwa_data = null;

            }
        } catch (\Throwable $th) {
            $pwa_data = null;

        }

        // Cookie Data
        $cookieDetail = [];
        $filename = '';
        $filename = $store->slug . '.csv';
        $cookieDetail = [];


        $gdpr_text_json = $company_setting['gdpr_text'] ?? '';
        $gdpr_text_array = json_decode($gdpr_text_json, true);
        $device_type = VcardStore::GetDeviceType($_SERVER['HTTP_USER_AGENT']);


        // If you want to use it for cookieDetail
        $cookieDetail = json_decode($gdpr_text_json);


        $PixelFields = PixelFields::where('store_id', $store->id)->get();
        $pixelScript = [];
        foreach ($PixelFields as $pixel) {

            if (!$pixel->disabled) {
                $pixelScript[] = VcardStore::pixelSourceCode($pixel['platform'], $pixel['pixel_id']);
            }
        }

        $contactinfo = ContactInfo::cardContactData($store->id);
        $contactinfo_content = [];
        if (!empty($contactinfo->content)) {
            $contactinfo_content = json_decode($contactinfo->content);
        }

        $currencyData = VcardStore::getCurrency();
        $value = json_decode($store->card_theme);

        $storefields = $businessfields = Storefield::getFields();
        $card_theme = [];
        $card_theme['theme'] = $value->theme ?? "theme1";
        $card_theme['order'] = VcardStore::getDefaultThemeOrder($value->theme ?? "theme1");
        $cardTheme = json_decode($store['card_theme'], true);

        if ($store->card_theme == null) {
            $card_theme['order'] = VcardStore::getDefaultThemeOrder($value->theme ?? "theme1");
        } else {
            if($cardTheme['order']){
            $card_theme['order'] = $cardTheme['order'];
            }
        }

        //theame side variables 
        $qr_code = VcardStore::$qr_type;
        $qr_detail = Storeqr::where('store_id', $store->id)->first();
        $color = isset($store->theme_color) ? substr($store->theme_color, 0, 6) : "color1";
        $social_no = 1;
        $appointment_no = 0;
        $service_row_no = 0;
        $testimonials_row_no = 0;
        $gallery_row_no = 0;
        $product_row_no = 0;
        $no = 1;
        $business = $store;
        $stringid = $store->id;
        $company_favicon = get_module_img('Marketing');
        $a = VcardStore::card_cookie($store->slug);
        $url_link = route('get.vcard', ['slug' => $store->slug]);
        $custom_html = Appoinment::Marketing('custom_html_text');
        $is_branding_enabled = false;
        $branding = Appoinment::Marketing('branding_text');
        $is_gdpr_enabled = false;
        $gdpr_text = Appoinment::Marketing('gdpr_text');
        $metaImg = get_file(Appoinment::Marketing('metaimage'));
        $metaKeyword = Appoinment::Marketing('metakeyword');
        $metaDesc = Appoinment::Marketing('metadesc');
        $customCss = Appoinment::Marketing('customcss');
        $googleFonts = Appoinment::Marketing('google_fonts');
        $enablePWABusiness = Appoinment::Marketing('enable_pwa_business');
        $brandingText = Appoinment::Marketing('branding_text');

        $SITE_RTL = getAdminAllSetting($store->created_by, $store->id);
        if (isset($SITE_RTL['SITE_RTL'])) {
            $SITE_RTL = $SITE_RTL['SITE_RTL'];
        } else {
            $SITE_RTL = 'off';
        }

        $is_custom_html_enable = false;
        if (!is_null($custom_html) && !is_null($company_setting)) {
            Appoinment::Marketing('is_custom_html_enabled') == '1' ? ($is_custom_html_enable = true) : ($is_custom_html_enable = false);
        }

        if (!is_null(Appoinment::Marketing('is_branding_enabled')) && !is_null(Appoinment::Marketing('is_branding_enabled'))) {
            !empty(Appoinment::Marketing('is_branding_enabled')) && Appoinment::Marketing('is_branding_enabled') == 'on'
                ? ($is_branding_enabled = true)
                : ($is_branding_enabled = false);
        } else {
            $is_branding_enabled = false;
        }

        if (!is_null(Appoinment::Marketing('is_gdpr_enabled')) && !is_null(Appoinment::Marketing('is_gdpr_enabled'))) {
            !empty(Appoinment::Marketing('is_gdpr_enabled')) && Appoinment::Marketing('is_gdpr_enabled') == 'on'
                ? ($is_gdpr_enabled = true)
                : ($is_gdpr_enabled = false);
        }
       
        return view('marketing::store.index', compact('company_setting', 'metaDesc', 'enablePWABusiness', 'brandingText', 
          'color', 'googleFonts', 'social_no', 'customCss', 'metaKeyword', 'appointment_no', 'service_row_no', 'testimonials_row_no',
          'custom_html', 'is_branding_enabled', 'branding', 'is_gdpr_enabled', 'gdpr_text', 'is_custom_html_enable', 'metaImg', 'gallery_row_no',
          'product_row_no', 'no', 'SITE_RTL', 'a', 'url_link', 'company_favicon', 'business', 'device_type', 'stringid', 'filename', 'cookieDetail', 
          'pwa_data', 'currencyData', 'qr_code', 'qr_detail', 'PixelFields', 'pixelScript', 'subdomainPointing', 'store', 'serverIp', 'domainip', 'domainPointing', 
          'mp4_msg', 'subdomain_name', 'gallery_contents', 'gallaryoption', 'services_content', 'social_content', 'testimonials_content', 'products_content', 
          'appoinment_hours', 'themeOne', 'days', 'business_hours', 'businesshours', 'contactinfo_content', 'contactinfo', 'storefields', 'business_url', 
          'businessfields', 'card_theme', 'appoinment', 'services', 'products', 'testimonials', 'sociallinks', 'gallery', 'cardPayment_content', 'cardPayment'));

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('marketing::create');
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
        return view('marketing::show');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit($id)
    {
        return view('marketing::edit');
    }

    public function getcard($slug)
    {
        $business = VcardStore::getBusinessBySlug($slug);

        $store = getStore($slug);

        if (!is_null($business)) {
            if ($business->status != 'locked') {
                \App::setLocale($business->getLanguage());
                $is_slug = "true";

                $businessfields = Storefield::getFields();
                $businesshours = BusinessHours::where('store_id', $store->id)->first();
                $appoinment = Appoinment::where('store_id', $store->id)->first();
                $appoinment_hours = [];
                if (!empty($appoinment->content)) {
                    $appoinment_hours = json_decode($appoinment->content);
                }

                $services = Service::where('store_id', $store->id)->first();
                $services_content = [];
                if (!empty($services->content)) {
                    $services_content = json_decode($services->content);
                }

                $testimonials = Testimonial::where('store_id', $store->id)->first();
                $testimonials_content = [];
                if (!empty($testimonials->content)) {
                    $testimonials_content = json_decode($testimonials->content);
                }

                $contactinfo = ContactInfo::where('store_id', $store->id)->first();
                $contactinfo_content = [];
                if (!empty($contactinfo->content)) {
                    $contactinfo_content = json_decode($contactinfo->content);
                }

                $sociallinks = Social::where('store_id', $store->id)->first();
                $social_content = [];
                if (!empty($sociallinks->content)) {
                    $social_content = json_decode($sociallinks->content);
                }

                //Gallery
                $gallery = Gallery::where('store_id', $store->id)->first();
                $gallery_contents = [];
                if (!empty($gallery->content)) {
                    $gallery_contents = json_decode($gallery->content);
                }



                $customhtml = $company_setting = getAdminAllSetting($store->created_by, $store->id);

                $days = BusinessHours::$days;
                $business_hours = '';
                if (!empty($businesshours->content)) {
                    $business_hours = json_decode($businesshours->content);
                }
                if (json_decode($business->card_theme) == NULL) {
                    $card_order = [];
                    $card_order['theme'] = $business->card_theme;
                    $card_order['order'] = vcardstore::getDefaultThemeOrder($business->card_theme);
                    $business->card_theme = json_encode($card_order);
                    $business->save();
                }
                $card_theme = json_decode($business->card_theme);

                $pixels = PixelFields::where('store_id', $store->id)->get();
                $pixelScript = [];
                foreach ($pixels as $pixel) {

                    if (!$pixel->disabled) {
                        $pixelScript[] = vcardstore::pixelSourceCode($pixel['platform'], $pixel['pixel_id']);
                    }
                }
                $products = CardProduct::where('store_id', $store->id)->first();
                $products_content = [];
                if (!empty($products->content)) {
                    $products_content = json_decode($products->content);
                }
                $cardPayment = CardPayment::cardPaymentData($business->id);
                $cardPayment_content = [];
                if (!empty($cardPayment->content)) {
                    $cardPayment_content = json_decode($cardPayment->content);
                }

                $device_type = VcardStore::GetDeviceType($_SERVER['HTTP_USER_AGENT']);

                $storefields = $businessfields = Storefield::getFields();
                $card_theme = json_decode($business->card_theme, true) ?? [];
                $card_theme['theme'] = $card_theme['theme'] ?? "theme1";
                $card_theme['order'] = VcardStore::getDefaultThemeOrder($card_theme['theme'] ?? "theme1");

                $themeOne = VcardStore::themeOne();
                $qr_detail = Storeqr::where('store_id', $store->id)->first();
                $business = $store;
                $stringid = $store->id;
                $color = isset($store->theme_color) ? substr($store->theme_color, 0, 6) : "color1";
                $SITE_RTL = getAdminAllSetting($store->created_by, $store->id);
                if (isset($SITE_RTL['SITE_RTL'])) {
                    $SITE_RTL = $SITE_RTL['SITE_RTL'];
                } else {
                    $SITE_RTL = 'off';
                }
                $company_favicon = get_module_img('Marketing');
                $a = VcardStore::card_cookie($store->slug);
                $url_link = route('get.vcard', ['slug' => $store->slug]);
                $social_no = 1;
                $appointment_no = 0;
                $service_row_no = 0;
                $testimonials_row_no = 0;
                $gallery_row_no = 0;
                $product_row_no = 0;
                $no = 1;

                $custom_html = Appoinment::Marketing('custom_html_text');
                $is_branding_enabled = false;
                $branding = Appoinment::Marketing('branding_text');
                $is_gdpr_enabled = false;
                $gdpr_text = Appoinment::Marketing('gdpr_text');
                $is_custom_html_enable = false;
                if (!is_null($custom_html) && !is_null($customhtml)) {
                    Appoinment::Marketing('is_custom_html_enabled') == '1' ? ($is_custom_html_enable = true) : ($is_custom_html_enable = false);
                }
                if (!is_null(Appoinment::Marketing('is_branding_enabled')) && !is_null(Appoinment::Marketing('is_branding_enabled'))) {
                    !empty(Appoinment::Marketing('is_branding_enabled')) && Appoinment::Marketing('is_branding_enabled') == 'on'
                        ? ($is_branding_enabled = true)
                        : ($is_branding_enabled = false);
                } else {
                    $is_branding_enabled = false;
                }
                if (!is_null(Appoinment::Marketing('is_gdpr_enabled')) && !is_null(Appoinment::Marketing('is_gdpr_enabled'))) {
                    !empty(Appoinment::Marketing('is_gdpr_enabled')) && Appoinment::Marketing('is_gdpr_enabled') == 'on'
                        ? ($is_gdpr_enabled = true)
                        : ($is_gdpr_enabled = false);
                }
                $metaKeyword = Appoinment::Marketing('metakeyword');
                $metaDesc = Appoinment::Marketing('metadesc');
                $metaImg = get_file(Appoinment::Marketing('metaimage'));
                $customCss = Appoinment::Marketing('customcss');
                $googleFonts = Appoinment::Marketing('google_fonts');
                $enablePWABusiness = Appoinment::Marketing('enable_pwa_business');
                $brandingText = Appoinment::Marketing('branding_text');

                return view('marketing::card.' . $card_theme["theme"] . '.index', compact('businessfields', 'brandingText', 'enablePWABusiness', 'googleFonts', 'customCss', 'metaImg', 'a', 'metaDesc', 'metaKeyword', 'social_no', 'custom_html', 'is_branding_enabled', 'branding', 'is_gdpr_enabled', 'gdpr_text', 'is_custom_html_enable', 'appointment_no', 'service_row_no', 'testimonials_row_no', 'gallery_row_no', 'product_row_no', 'no', 'url_link', 'company_favicon', 'SITE_RTL', 'color', 'device_type', 'business', 'stringid', 'store', 'themeOne', 'company_setting', 'contactinfo', 'contactinfo_content', 'appoinment_hours', 'appoinment', 'services_content', 'services', 'testimonials_content', 'testimonials', 'social_content', 'sociallinks', 'storefields', 'card_theme', 'customhtml', 'businesshours', 'business_hours', 'business', 'days', 'is_slug', 'gallery', 'gallery_contents', 'pixelScript', 'qr_detail', 'products', 'products_content', 'cardPayment', 'cardPayment_content'));
            } else {
                return abort('403', __('The Link You Followed Has Inactive'));
            }

        } else {
            return abort('403', __('The Link You Followed Has Expired'));
        }
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


    public function destroyGallery(Request $request)
    {
        $id = $request->store_id;
        $data_id = $request->id;

        $gallery = Gallery::where('store_id', $id)->first();
        $gallery_details = json_decode($gallery->content);

        $gallery_detailss = [];
        foreach ($gallery_details as $key => $data) {
            if ($data->id != $data_id) {
                $gallery_detailss[] = $data;
            }
        }

        $gallery_content = json_encode($gallery_detailss);
        $gallery->content = $gallery_content;
        $gallery->save();
        Session::put(['tab' => 2]);
        return true;
    }



    public function storedata(Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Update Theme')) {
            $id = getCurrentStore();

            $theme_id = APP_THEME();
            $validator = \Validator::make(
                $request->all(),
                [
                    'theme_color' => 'required',
                    'themefile' => 'required',
                ]
            );
            if ($validator->fails()) {
                $messages = $validator->getMessageBag();

                return redirect()->back()->with('error', $messages->first());
            }
            $card_order = [];
            $card_order['theme'] = $request->themefile;
            $card_order['order'] = VcardStore::getDefaultThemeOrder($request->themefile);
            $businesss = getStoreById($id);
            $businesss['theme_color'] = $request->theme_color;
            $businesss['card_theme'] = json_encode($card_order);
            $businesss->save();

            $tab = 1;
            return redirect()->back()->with('success', __('Theme Successfully Updated.'));
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));

        }

    }

    public function updatedata(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Update Store Data')) {


            $user = Store::find($id);
            $creatorId = $user->created_by;
            $user = \Auth::user();
            $store = getStoreById($id);

            if (!is_null($store)) {
                $count = Store::where('id', $store->id)->count();
                if ($count == 0) {
                    return redirect()->route('vcard.store')->with('error', __('This store number is not yours.'));
                }

                $validator = \Validator::make(
                    $request->all(),
                    [
                        'banner' => 'required_if:store.banner,null',
                        'logo' => 'required_if:store.logo,null',
                    ]
                );

                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }

                $count = Store::where('slug', $request->slug)->count();
                if ($count == 0 || ($count == 1 && $store->slug == $request->slug)) {
                    $store->slug = $request->slug;
                } else {
                    return redirect()->route('vcard.store')->with('error', __('Slug is already used.'));
                }

                $store->name = $request->title;
                $store->industry = $request->sub_title;
                $store->description = $request->description;
                $store->Company = $request->designation;
                
                $totalImageSize = 0;
                if ($request->hasFile('logo')) {
                    $totalImageSize += $request->file('logo')->getSize();
                }
                if ($request->hasFile('banner')) {
                    $totalImageSize += $request->file('banner')->getSize();
                }
                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $totalImageSize);
                if ($result != 1) {
                    return redirect()->back()->with('error', $result);
                }

                if ($request->hasFile('logo')) {
                    $filenameWithExt = $request->file('logo')->getClientOriginalName();
                    $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                    $extension = $request->file('logo')->getClientOriginalExtension();

                    $fileName = 'logo_' . $filename . time() . rand() . '.' . $extension;
                    $dir = 'uploads/card_banner';
                    $uploadcard_logo = Utility::upload_file($request, 'logo', $fileName, $dir, []);

                    if ($uploadcard_logo['flag'] == 1) {
                        $url = $uploadcard_logo['url'];
                        if (!empty($store->logo) && \File::exists(base_path($store->logo))) {
                            Utility::changeStorageLimit(\Auth::user()->creatorId(), $store->logo);
                        }
                    } else {
                        return redirect()->back()->with('error', $uploadcard_logo['msg']);
                    }
                    $store->logo = $url;

                }

                if ($request->hasFile('banner')) {

                    $filenameWithExt = $request->file('banner')->getClientOriginalName();
                    $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                    $extension = $request->file('banner')->getClientOriginalExtension();
                    $fileName = 'banner_' . $filename . time() . rand() . '.' . $extension;
                    $dir = 'uploads/card_banner';
                    $uploadcard_banner = Utility::upload_file($request, 'banner', $fileName, $dir, []);

                    if ($uploadcard_banner['flag'] == 1) {
                        $url = $uploadcard_banner['url'];
                        if (!empty($store->banner) && \File::exists(base_path($store->banner))) {
                            Utility::changeStorageLimit(\Auth::user()->creatorId(), $store->banner);
                        }
                    } else {
                        return redirect()->back()->with('error', $uploadcard_banner['msg']);
                    }
                    $store->banner = $url;

                }



                //Contact Info
                if ($request->is_contacts_enabled == "on") {
                    $contacts_content = json_encode($request->contact);
                    $contactsinfo = ContactInfo::where('store_id', $store->id)->first();

                    if (!is_null($contactsinfo)) {
                        if ($contacts_content != 'null') {
                            $contactsinfo->content = $contacts_content;
                            $contactsinfo->is_enabled = '1';
                            $contactsinfo->created_by = $creatorId;
                            $contactsinfo->save();

                        } else {
                            $contactsinfo->content = $contacts_content;
                            $contactsinfo->is_enabled = '1';
                            $contactsinfo->created_by = $creatorId;
                            $contactsinfo->save();
                        }

                    } else {

                        ContactInfo::create([
                            'store_id' => $store->id,
                            
                            'content' => $contacts_content,
                            'is_enabled' => '1',
                            'created_by' => $creatorId
                        ]);

                    }
                } else {
                    $contactsinfo = ContactInfo::where('store_id', $store->id)->first();
                    if (!is_null($contactsinfo)) {
                        $contactsinfo->is_enabled = '0';
                        $contactsinfo->created_by = $creatorId;
                        $contactsinfo->save();
                    } else {
                        ContactInfo::create([
                            'store_id' => $store->id,
                            
                            'is_enabled' => '0',
                            'created_by' => $creatorId
                        ]);
                    }
                }

                //Business Hours
                if ($request->is_business_hours_enabled == "on") {
                    $requestAll = $request->all();
                    $days = BusinessHours::$days;
                    $business_hours = [];
                    foreach ($days as $k => $day) {
                        $time['days'] = isset($requestAll['days_' . $k]) ? 'on' : 'off';
                        $time['start_time'] = $requestAll['start-' . $k];
                        $time['end_time'] = $requestAll['end-' . $k];
                        $business_hours[$k] = $time;
                    }
                    $business_hours = json_encode($business_hours);
                    $businessHours = BusinessHours::where('store_id', $store->id)->first();
                    if (!is_null($businessHours)) {
                        $businessHours->content = $business_hours;
                        $businessHours->is_enabled = '1';
                        $businessHours->created_by = $creatorId;
                        $businessHours->save();
                    } else {
                        BusinessHours::create([
                            'store_id' => $store->id,
                            
                            'content' => $business_hours,
                            'is_enabled' => '1',
                            'created_by' => $creatorId
                        ]);
                    }
                } else {
                    $businessHours = BusinessHours::where('store_id', $store->id)->first();
                    if (!is_null($businessHours)) {
                        $businessHours->is_enabled = '0';
                        $businessHours->created_by = $creatorId;
                        $businessHours->save();
                    } else {
                        BusinessHours::create([
                            'store_id' => $store->id,
                            
                            'is_enabled' => '0',
                            'created_by' => $creatorId
                        ]);
                    }
                }

                //appointment

                if ($request->is_appoinment_enabled == "on") {
                    $app_hours = $request->hours;
                    $appointment_count = 1;
                    $appoinment_hours = [];
                    $hours = [];
                    if (!empty($app_hours)) {
                        foreach ($app_hours as $business_hours_key => $business_hours_val) {
                            $hours['id'] = $appointment_count;
                            $hours['start'] = $business_hours_val['start'];
                            $hours['end'] = $business_hours_val['end'];
                            $appoinment_hours[$business_hours_key] = $hours;
                            $appointment_count++;
                        }
                        $appoinment_hours = json_encode($appoinment_hours);
                        $appoinment = Appoinment::where('store_id', $store->id)->first();
                        if (!is_null($appoinment)) {
                            $appoinment->content = $appoinment_hours;
                            $appoinment->is_enabled = '1';
                            $appoinment->created_by = $creatorId;
                            $appoinment->save();
                        } else {
                            Appoinment::create([
                                'store_id' => $store->id,
                                
                                'content' => $appoinment_hours,
                                'is_enabled' => '1',
                                'created_by' => $creatorId
                            ]);
                        }

                    } else {
                        $appoinment_hours = json_encode($appoinment_hours);
                        $appoinment = Appoinment::where('store_id', $store->id)->first();
                        if (!is_null($appoinment)) {
                            $appoinment->content = $appoinment_hours;
                            $appoinment->is_enabled = '1';
                            $appoinment->created_by = $creatorId;
                            $appoinment->save();
                        } else {
                            Appoinment::create([
                                'store_id' => $store->id,
                                
                                'content' => $appoinment_hours,
                                'is_enabled' => '1',
                                'created_by' => $creatorId
                            ]);
                        }

                    }
                } else {
                    $appoinment = Appoinment::where('store_id', $store->id)->first();
                    if (!is_null($appoinment)) {
                        $appoinment->is_enabled = '0';
                        $appoinment->created_by = $creatorId;
                        $appoinment->save();
                    } else {
                        Appoinment::create([
                            'store_id' => $store->id,
                            
                            'is_enabled' => '0',
                            'created_by' => $creatorId
                        ]);
                    }
                }
                //service
                if ($request->is_services_enabled == "on") {

                    $servicedetails = $request->services;
                    $service_count = 1;
                    $service_details = [];
                    $details = [];
                    $requestImg = [];
                    if (!empty($servicedetails)) {

                        foreach ($servicedetails as $service_key => $service_val) {
                            $images = $request->file('services');
                            $details['id'] = $service_count;
                            $details['title'] = $service_val['title'];
                            $details['description'] = $service_val['description'];
                            $details['purchase_link'] = $service_val['purchase_link'];
                            $details['link_title'] = $service_val['link_title'];

                            if (isset($images[$service_key])) {
                                $filenameWithExt = $images[$service_key]['image']->getClientOriginalName();
                                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                                $extension = $images[$service_key]['image']->getClientOriginalExtension();
                                $fileNameToStore = 'img' . $filename . '_' . time() . '.' . $extension;


                                $requestImg['image'] = $images[$service_key]['image'];
                                $myRequest = new Request();
                                $myRequest->request->add(['image' => $requestImg['image']]);
                                $myRequest->files->add(['image' => $requestImg['image']]);
                                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $images[$service_key]['image']->getSize());
                                if ($result != 1) {
                                    return redirect()->back()->with('error', $result);
                                }
                                $dir = 'uploads/service_images';
                                $upload_service_image = Utility::upload_file($myRequest, 'image', $fileNameToStore, $dir, []);



                                if ($upload_service_image['flag'] == 1) {
                                    $url = $upload_service_image['url'];
                                } else {
                                    return redirect()->back()->with('error', $upload_service_image['msg']);
                                }
                                $details['image'] = $url;


                            } else {

                                if (isset($service_val['get_image']) && !is_null($service_val['get_image'])) {
                                    $details['image'] = $service_val['get_image'];
                                } else {
                                    $details['image'] = "";
                                }
                            }

                            $service_details[$service_key] = $details;
                            $service_count++;

                        }
                        $service_details = json_encode($service_details);

                        $services_data = service::where('store_id', $store->id)->first();
                        if (!is_null($services_data)) {

                            if ($service_details != 'null') {
                                $services_data->content = $service_details;
                                $services_data->is_enabled = '1';
                                $services_data->created_by = $creatorId;
                                $services_data->save();
                            } else {
                                $services_data->is_enabled = '1';
                                $services_data->created_by = $creatorId;
                                $services_data->save();
                            }
                        } else {
                            service::create([
                                'store_id' => $store->id,
                                
                                'content' => $service_details,
                                'is_enabled' => '1',
                                'created_by' => $creatorId
                            ]);
                        }
                    } else {
                        $service_details = json_encode($service_details);
                        $services_data = Service::where('store_id', $store->id)->first();
                        if (!is_null($services_data)) {

                            if ($service_details != 'null') {
                                $services_data->content = $service_details;
                                $services_data->is_enabled = '1';
                                $services_data->created_by = $creatorId;
                                $services_data->save();
                            } else {
                                $services_data->is_enabled = '1';
                                $services_data->created_by = $creatorId;
                                $services_data->save();
                            }
                        } else {
                            Service::create([
                                'store_id' => $store->id,
                                
                                'content' => $service_details,
                                'is_enabled' => '1',
                                'created_by' => $creatorId
                            ]);
                        }
                    }
                } else {
                    $services_data = Service::where('store_id', $store->id)->first();
                    if (!is_null($services_data)) {
                        $services_data->is_enabled = '0';
                        $services_data->created_by = $creatorId;
                        $services_data->save();
                    } else {
                        Service::create([
                            'store_id' => $store->id,
                            
                            'is_enabled' => '0',
                            'created_by' => $creatorId
                        ]);
                    }
                }

                // Testimonial
                if ($request->is_testimonials_enabled == "on") {

                    $testimonialsdetails = $request->testimonials;
                    $testimonials_count = 1;
                    $testimonials_details = [];
                    $testi_details = [];
                    $requestImg = [];

                    if (!empty($testimonialsdetails)) {
                        foreach ($testimonialsdetails as $testimonials_key => $testimonials_val) {
                            $testimonials_images = $request->file('testimonials');
                            $testi_details['id'] = $testimonials_count;
                            if (isset($testimonials_val['rating'])) {
                                $testi_details['rating'] = $testimonials_val['rating'];
                            } else {
                                $testi_details['rating'] = "0";
                            }
                            if (isset($testimonials_val['name']) && $testimonials_val['name'] != 'null') {
                                $testi_details['name'] = $testimonials_val['name'];
                            } else {
                                $testi_details['name'] = '';
                            }
                            if (isset($testimonials_val['description']) && $testimonials_val['description'] != 'null') {
                                $testi_details['description'] = $testimonials_val['description'];
                            } else {
                                $testi_details['description'] = '';
                            }

                            if (isset($testimonials_images[$testimonials_key])) {
                                $filenameWithExt = $testimonials_images[$testimonials_key]['image']->getClientOriginalName();
                                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                                $extension = $testimonials_images[$testimonials_key]['image']->getClientOriginalExtension();
                                $fileNameToStore = 'img' . $filename . '_' . time() . '.' . $extension;


                                $requestImg['image'] = $testimonials_images[$testimonials_key]['image'];
                                $testimonialRequest = new Request();
                                $testimonialRequest->request->add(['image' => $requestImg['image']]);
                                $testimonialRequest->files->add(['image' => $requestImg['image']]);
                                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $testimonials_images[$testimonials_key]['image']->getSize());
                                if ($result != 1) {
                                    return redirect()->back()->with('error', $result);
                                }
                                $dir = 'uploads/testimonials_images';
                                $upload_testimonial_image = Utility::upload_file($testimonialRequest, 'image', $fileNameToStore, $dir, []);


                                if ($upload_testimonial_image['flag'] == 1) {
                                    $url1 = $upload_testimonial_image['url'];
                                } else {
                                    return redirect()->back()->with('error', $upload_testimonial_image['msg']);
                                }
                                $testi_details['image'] = $url1;


                            } else {
                                if (isset($testimonials_val['get_image']) && !is_null($testimonials_val['get_image'])) {
                                    $testi_details['image'] = $testimonials_val['get_image'];
                                } else {
                                    $testi_details['image'] = '';

                                }
                            }
                            $testimonials_details[$testimonials_key] = $testi_details;
                            $testimonials_count++;
                        }
                        $testimonials_details = json_encode($testimonials_details);


                        $testimonials_data = testimonial::where('store_id', $store->id)->first();
                        if (!is_null($testimonials_data)) {
                            if ($testimonials_details != 'null') {
                                $testimonials_data->content = $testimonials_details;
                                $testimonials_data->is_enabled = '1';
                                $testimonials_data->created_by = $creatorId;
                                $testimonials_data->save();
                            } else {
                                $testimonials_data->is_enabled = '1';
                                $testimonials_data->created_by = $creatorId;
                                $testimonials_data->save();
                            }
                        } else {
                            testimonial::create([
                                'store_id' => $store->id,
                                
                                'content' => $testimonials_details,
                                'is_enabled' => '1',
                                'created_by' => $creatorId
                            ]);
                        }
                    } else {
                        $testimonials_details = json_encode($testimonials_details);

                        $testimonials_data = testimonial::where('store_id', $store->id)->first();
                        if (!is_null($testimonials_data)) {
                            if ($testimonials_details != 'null') {
                                $testimonials_data->content = $testimonials_details;
                                $testimonials_data->is_enabled = '1';
                                $testimonials_data->created_by = $creatorId;
                                $testimonials_data->save();
                            } else {
                                $testimonials_data->is_enabled = '1';
                                $testimonials_data->created_by = $creatorId;
                                $testimonials_data->save();
                            }
                        } else {
                            testimonial::create([
                                'store_id' => $store->id,
                                
                                'content' => $testimonials_details,
                                'is_enabled' => '1',
                                'created_by' => $creatorId
                            ]);
                        }
                    }


                } else {
                    $testimonials_data = testimonial::where('store_id', $store->id)->first();
                    if (!is_null($testimonials_data)) {
                        $testimonials_data->is_enabled = '0';
                        $testimonials_data->created_by = $creatorId;
                        $testimonials_data->save();
                    } else {
                        testimonial::create([
                            'store_id' => $store->id,
                            
                            'is_enabled' => '0',
                            'created_by' => $creatorId
                        ]);
                    }
                }

                //Product
                if ($request->is_product_enabled == "on") {
                    $productdetails = $request->product;
                    $product_count = 1;
                    $product_details = [];
                    $prdetails = [];
                    $requestProductImg = [];
                    if (!empty($productdetails)) {
                        foreach ($productdetails as $product_key => $product_val) {

                            $pr_images = $request->file('product');
                            $prdetails['id'] = $product_count;
                            $prdetails['title'] = $product_val['title'];
                            $prdetails['description'] = $product_val['description'];
                            $prdetails['price'] = $product_val['price'];
                            $prdetails['purchase_link'] = $product_val['purchase_link'];
                            $prdetails['link_title'] = $product_val['link_title'];
                            $prdetails['currency'] = $product_val['currency'];

                            if (isset($pr_images[$product_key])) {
                                $filenameWithExt = $pr_images[$product_key]['image']->getClientOriginalName();
                                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                                $extension = $pr_images[$product_key]['image']->getClientOriginalExtension();
                                $fileNameToStore = 'img' . $filename . '_' . time() . '.' . $extension;


                                $requestProductImg['image'] = $pr_images[$product_key]['image'];
                                $myRequestProduct = new Request();
                                $myRequestProduct->request->add(['image' => $requestProductImg['image']]);
                                $myRequestProduct->files->add(['image' => $requestProductImg['image']]);

                                $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $pr_images[$product_key]['image']->getSize());
                                if ($result != 1) {
                                    return redirect()->back()->with('error', $result);
                                }
                                $dir = 'uploads/card_product_image';
                                $upload_product_image = Utility::upload_file($myRequestProduct, 'image', $fileNameToStore, $dir, []);



                                if ($upload_product_image['flag'] == 1) {
                                    $url = $upload_product_image['url'];
                                } else {
                                    return redirect()->back()->with('error', $upload_product_image['msg']);
                                }
                                $prdetails['image'] = $url;
                            } else {
                                if (isset($product_val['get_image']) && !is_null($product_val['get_image'])) {
                                    $prdetails['image'] = $product_val['get_image'];
                                } else {
                                    $prdetails['image'] = "";
                                }
                            }
                            $product_details[$product_key] = $prdetails;
                            $product_count++;

                        }
                        $product_details = json_encode($product_details);

                        $product_data = CardProduct::where('store_id', $store->id)->first();
                        if (!is_null($product_data)) {

                            if ($product_details != 'null') {
                                $product_data->content = $product_details;
                                $product_data->is_enabled = '1';
                                $product_data->created_by = $creatorId;
                                $product_data->save();
                            } else {
                                $product_data->is_enabled = '1';
                                $product_data->created_by = $creatorId;
                                $product_data->save();
                            }
                        } else {
                            CardProduct::create([
                                'store_id' => $store->id,
                                
                                'content' => $product_details,
                                'is_enabled' => '1',
                                'created_by' => $creatorId
                            ]);
                        }
                    } else {
                        $product_details = json_encode($product_details);
                        $product_data = CardProduct::where('store_id', $store->id)->first();
                        if (!is_null($product_data)) {

                            if ($product_details != 'null') {
                                $product_data->content = $product_details;
                                $product_data->is_enabled = '1';
                                $product_data->created_by = $creatorId;
                                $product_data->save();
                            } else {
                                $product_data->is_enabled = '1';
                                $product_data->created_by = $creatorId;
                                $product_data->save();
                            }
                        } else {
                            CardProduct::create([
                                'store_id' => $store->id,
                                
                                'content' => $product_details,
                                'is_enabled' => '1',
                                'created_by' => $creatorId
                            ]);
                        }
                    }
                } else {
                    $product_data = CardProduct::where('store_id', $store->id)->first();
                    if (!is_null($product_data)) {
                        $product_data->is_enabled = '0';
                        $product_data->created_by = $creatorId;
                        $product_data->save();
                    } else {
                        CardProduct::create([
                            'store_id' => $store->id,
                            
                            'is_enabled' => '0',
                            'created_by' => $creatorId
                        ]);
                    }
                }




                //social
                if ($request->is_socials_enabled == "on") {

                    $sociallinks_content = json_encode($request->socials);
                    $sociallinks = Social::where('store_id', $store->id)->first();

                    if (!is_null($sociallinks)) {

                        if ($sociallinks_content != 'null') {
                            $sociallinks->content = $sociallinks_content;
                            $sociallinks->is_enabled = '1';
                            $sociallinks->created_by = $creatorId;
                            $sociallinks->save();
                        } else {

                            $sociallinks->content = $sociallinks_content;
                            $sociallinks->is_enabled = '1';
                            $sociallinks->created_by = $creatorId;
                            $sociallinks->save();
                        }

                    } else {
                        if ($sociallinks_content != 'null') {
                            Social::create([
                                'store_id' => $store->id,
                                
                                'content' => $sociallinks_content,
                                'is_enabled' => '1',
                                'created_by' => $creatorId
                            ]);
                        }
                    }
                } else {
                    $sociallinks = Social::where('store_id', $store->id)->first();
                    if (!is_null($sociallinks)) {
                        $sociallinks->is_enabled = '0';
                        $sociallinks->created_by = $creatorId;
                        $sociallinks->save();
                    } else {
                        Social::create([
                            'store_id' => $store->id,
                            
                            'is_enabled' => '0',
                            'created_by' => $creatorId
                        ]);
                    }
                }



                //Custom Html

                if ($request->is_custom_html_enabled == "on") {
                    $custom_html = str_replace(array("\r\n"), "", $request->custom_html_text);
                    $post['is_custom_html_enabled'] = '1';
                    $post['custom_html_text'] = $custom_html;

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
                } else { 
                    $custom_html = str_replace(array("\r\n"), "", $request->custom_html_text);

                    $post['is_custom_html_enabled'] = '0';
                    $post['custom_html_text'] = $custom_html;

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


                }

                // Gallery
                if ($request->is_gallery_enabled == "on") {
                    $gallery_data = explode(",", $request->galary_data); //pass when data is not empty

                    $galleryNewImage = '';
                    $details = [];
                    $gallery_details = [];
                    $gallery_content = [];
                    $image_data = '';

                    $galleryinfo = Gallery::where('store_id', $store->id)->first();
                    if (!empty($galleryinfo->content)) {
                        $gallery_content = (array) json_decode($galleryinfo->content);
                        foreach ($gallery_content as $key => $data) {
                            $image_data = $data->value;
                        }
                    }

                    if ($request->hasFile('upload_video')) {
                        $filenameWithExt = $request->file('upload_video')->getClientOriginalName();
                        $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                        $video = $request->file('upload_video');
                        $extension = $request->file('upload_video')->getClientOriginalExtension();
                        $fileName = 'video_' . $filename . time() . rand() . '.' . $extension;

                        if ($request->hasFile('upload_video')) {
                            $imageSize = $request->file('upload_video')->getSize();
                        }
                        $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $imageSize);
                        if ($result != 1) {
                            return redirect()->back()->with('error', $result);
                        }
                        $dir = 'uploads/gallery';
                        $upload_gallery_video = Utility::upload_file($request, 'upload_video', $fileName, $dir, []);


                        if ($upload_gallery_video['flag'] == 1) {
                            $url = $upload_gallery_video['url'];
                        } else {
                            return redirect()->back()->with('error', $upload_gallery_video['msg']);
                        }
                        $galleryNewImage = $url;
                    }



                    if ($request->hasFile('upload_image')) {
                        $filenameWithExt = $request->file('upload_image')->getClientOriginalName();
                        $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                        $images = $request->file('upload_image');
                        $extension = $request->file('upload_image')->getClientOriginalExtension();
                        $fileName = 'image_' . $filename . time() . rand() . '.' . $extension;


                        if ($request->hasFile('upload_image')) {
                            $imageSize = $request->file('upload_image')->getSize();
                        }
                        $result = Utility::updateStorageLimit(\Auth::user()->creatorId(), $imageSize);
                        if ($result != 1) {
                            return redirect()->back()->with('error', $result);
                        }
                        $dir = 'uploads/gallery';
                        $upload_gallery_image = Utility::upload_file($request, 'upload_image', $fileName, $dir, []);

                        if ($upload_gallery_image['flag'] == 1) {
                            $url = $upload_gallery_image['url'];
                        } else {
                            return redirect()->back()->with('error', $upload_gallery_image['msg']);
                        }
                        $galleryNewImage = $url;
                    }
                    if ($request->galleryoption == 'custom_image_link') {
                        $galleryNewImage = $request->custom_image_link;
                    }

                    if ($request->galleryoption == 'custom_video_link') {
                        $galleryNewImage = $request->custom_video_link;
                    }

                    if ($request->galleryoption != null && $galleryNewImage != '') {

                        $details['id'] = $request->gallery_count;
                        $details['type'] = $request->galleryoption;
                        $details['value'] = $galleryNewImage;
                        $gallery_details = (object) $details;
                        $gallery_content[] = $gallery_details;
                    }


                    $gallery_contents = [];
                    foreach ($gallery_content as $key => $value) {
                        $gallery_contents[] = [
                            'id' => $key,
                            'type' => $value->type,
                            'value' => $value->value,
                        ];
                    }


                    if (!is_null($galleryinfo)) {
                        if ($gallery_details != 'null') {
                            $galleryinfo->content = json_encode($gallery_contents);
                            $galleryinfo->is_enabled = '1';
                            $galleryinfo->created_by = $creatorId;
                            $galleryinfo->save();

                        } else {
                            $galleryinfo->content = $gallery_details;
                            $galleryinfo->is_enabled = '1';
                            $galleryinfo->created_by = $creatorId;
                            $galleryinfo->save();
                        }

                    } else {

                        Gallery::create([
                            'store_id' => $store->id,
                            
                            'content' => json_encode($gallery_contents),
                            'is_enabled' => '1',
                            'created_by' => $creatorId
                        ]);
                    }


                } else {

                    $gallery_info = Gallery::where('store_id', $store->id)->first();
                    if (!is_null($gallery_info)) {
                        $gallery_info->is_enabled = '0';
                        $gallery_info->created_by = $creatorId;
                        $gallery_info->save();
                    } else {
                        Gallery::create([
                            'store_id' => $store->id,
                            
                            'is_enabled' => '0',
                            'created_by' => $creatorId
                        ]);
                    }
                }


                $store->save();
                $tab = 2;
                return redirect()->back()->with('success', __('Business Information Add Successfully'))->with('tab', $tab);

            }
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));

        }
    }


    public function blocksetting($id, Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Update Block Setting')) {


            $user = Store::find($id);
            $creatorId = $user->created_by;
            $business = $store = getStoreById(getCurrentStore());
            $card_order = [];
            $order = [];
            $card_order['theme'] = $request->theme_name;
            $req_order = explode(",", $request->order);
            foreach ($req_order as $key => $value) {
                $od = $key + 1;
                $order[$value] = $od;
            }
            $card_order['order'] = $order;
            $business->card_theme = $card_order;
            $business->save();


            $contact_data = ContactInfo::where('store_id', $id)->first();

            if ($contact_data != NULL) {
                $contact_data['is_enabled'] = $request->is_contact_info_enabled == 'on' ? '1' : '0';
                $contact_data->save();
            } else {
                ContactInfo::create([
                    'store_id' => $id,
                    
                    'is_enabled' => $request->is_contact_info_enabled == 'on' ? '1' : '0',
                    'created_by' => $creatorId
                ]);
            }

            $bussiness_hour_data = BusinessHours::where('store_id', $id)->first();

            if ($bussiness_hour_data != NULL) {
                $bussiness_hour_data['is_enabled'] = $request->is_bussiness_hour_enabled == 'on' ? '1' : '0';
                $bussiness_hour_data->save();
            } else {
                BusinessHours::create([
                    'store_id' => $id,
                    
                    'is_enabled' => $request->is_bussiness_hour_enabled == 'on' ? '1' : '0',
                    'created_by' => $creatorId
                ]);
            }

            $appointment_data = appoinment::where('store_id', $id)->first();
            if ($appointment_data != NULL) {
                $appointment_data['is_enabled'] = $request->is_appointment_enabled == 'on' ? '1' : '0';
                $appointment_data->save();
            } else {
                appoinment::create([
                    'store_id' => $id,
                    
                    'is_enabled' => $request->is_appointment_enabled == 'on' ? '1' : '0',
                    'created_by' => $creatorId
                ]);
            }

            $service_data = service::where('store_id', $id)->first();
            if ($service_data != NULL) {
                $service_data['is_enabled'] = $request->is_service_enabled == 'on' ? '1' : '0';
                $service_data->save();
            } else {
                service::create([
                    'store_id' => $id,
                    
                    'is_enabled' => $request->is_service_enabled == 'on' ? '1' : '0',
                    'created_by' => $creatorId
                ]);
            }

            $testimonials_data = testimonial::where('store_id', $id)->first();
            if ($testimonials_data != NULL) {
                $testimonials_data['is_enabled'] = $request->is_testimonials_enabled == 'on' ? '1' : '0';
                $testimonials_data->save();
            } else {
                testimonial::create([
                    'store_id' => $id,
                    
                    'is_enabled' => $request->is_testimonials_enabled == 'on' ? '1' : '0',
                    'created_by' => $creatorId
                ]);
            }

            $social_data = social::where('store_id', $id)->first();
            if ($social_data != NULL) {
                $social_data['is_enabled'] = $request->is_social_enabled == 'on' ? '1' : '0';
                $social_data->save();
            } else {
                social::create([
                    'store_id' => $id,
                    
                    'is_enabled' => $request->is_social_enabled == 'on' ? '1' : '0',
                    'created_by' => $creatorId
                ]);
            }

            //Gallery
            $gallery_data = Gallery::where('store_id', $id)->first();
            if ($gallery_data != NULL) {
                $gallery_data['is_enabled'] = $request->is_gallery_enabled == 'on' ? '1' : '0';
                $gallery_data->save();
            } else {
                Gallery::create([
                    'store_id' => $id,
                    
                    'is_enabled' => $request->is_gallery_enabled == 'on' ? '1' : '0',
                    'created_by' => $creatorId
                ]);
            }
            $custom_html = getAdminAllSetting($store->created_by, $store->id);
            if ($custom_html != NULL) {
                $post['is_custom_html_enabled'] = $request->is_custom_html_enabled == 'on' ? '1' : '0';

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
            } else {

                $post['is_custom_html_enabled'] = $request->is_custom_html_enabled == 'on' ? '1' : '0';

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
            }


            $product_data = CardProduct::where('store_id', $id)->first();
            if ($product_data != NULL) {
                $product_data['is_enabled'] = $request->is_product_enabled == 'on' ? '1' : '0';
                $product_data->save();
            } else {
                CardProduct::create([
                    'store_id' => $id,
                    
                    'is_enabled' => $request->is_product_enabled == 'on' ? '1' : '0',
                    'created_by' => \Auth::user()->$creatorId
                ]);
            }
            $cardPayment_data = CardPayment::where('store_id', $id)->first();
            if ($cardPayment_data != NULL) {
                $cardPayment_data['is_enabled'] = $request->is_payment_enabled == 'on' ? '1' : '0';
                $cardPayment_data->save();
            } else {
                CardPayment::create([
                    'store_id' => $id,
                    
                    'is_enabled' => $request->is_payment_enabled == 'on' ? '1' : '0',
                    'created_by' => $creatorId
                ]);
            }
            $tab = 4;
            return redirect()->back()->with('success', __('Block Order Successfully Updated.'))->with('tab', $tab);


        } else {
            return redirect()->back()->with('error', __('Permission denied.'));

        }

    }



    public function domainsetting($id, Request $request)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Update Domain Setting')) {


            $business = Setting::where('id', $id)->where('name', 'enable_domain')->first();



            if ($request->enable_domain == 'enable_domain') {
                // Remove the http://, www., and slash(/) from the URL
                $input = $request->domains;
                // If URI is like, eg. www.way2tutorial.com/
                $input = trim($input, '/');
                // If not have http:// or https:// then prepend it
                if (!preg_match('#^http(s)?://#', $input)) {
                    $input = 'http://' . $input;
                }

                $urlParts = parse_url($input);
                // Remove www.
                $domain_name = preg_replace('/^www\./', '', $urlParts['host'] ?? null);

                // Output way2tutorial.com
            }
            if ($request->enable_domain == 'enable_subdomain') {
                // Remove the http://, www., and slash(/) from the URL
                $input = env('APP_URL');

                // If URI is like, eg. www.way2tutorial.com/
                $input = trim($input, '/');
                // If not have http:// or https:// then prepend it
                if (!preg_match('#^http(s)?://#', $input)) {
                    $input = 'http://' . $input;
                }

                $urlParts = parse_url($input);

                // Remove www.
                $subdomain_name = preg_replace('/^www\./', '', $urlParts['host']);
                // Output way2tutorial.com
                $subdomain_name = $request->subdomain . '.' . $subdomain_name;
            }

            if ($request->enable_domain == 'enable_domain') {
                $post['domains'] = $domain_name;
            }

            $post['enable_businesslink'] = ($request->enable_domain == 'enable_businesslink' || empty($request->enable_domain)) ? 'on' : 'off';
            $post['enable_domain'] = ($request->enable_domain == 'enable_domain') ? 'on' : 'off';
            $post['enable_subdomain'] = ($request->enable_domain == 'enable_subdomain') ? 'on' : 'off';

            if ($request->enable_domain == 'enable_subdomain') {
                $post['subdomain'] = $subdomain_name;
            }
            //CustomJs And CustomCSS

            if ($request->has('customjs') || $request->has('customcss')) {

                $post['customjs'] = $request->customjs;
                $post['customcss'] = $request->customcss;

            }

            //Google_Fonts
            if ($request->has('google_fonts')) {

                $post['google_fonts'] = $request->google_fonts;

            }

            //Password
            if ($request->password && $request->is_password_enabled) {

                $request->validate([
                    'password' => Rules\Password::defaults(),
                ]);


                $post['password'] = $request->password;
                $post['enable_password'] = $request->is_password_enabled;


            }

            //Branding
            if ($request->branding_text) {

                $post['is_branding_enabled'] = $request->branding;
                $post['branding_text'] = $request->branding_text;
            }


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


            $tab = 3;
            return redirect()->back()->with('success', __('Custom Detail Successfully Updated.'))->with('tab', $tab);

        } else {
            return redirect()->back()->with('error', __('Permission denied.'));

        }

    }




    public function saveseo(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Update SEO Setting')) {

            $post['metakeyword'] = $request->meta_keyword;
            $post['metadesc'] = $request->meta_description;
            $post['google_analytic'] = $request->google_analytic;
            $post['fbpixel_code'] = $request->fbpixel_code;



            if ($request->hasFile('meta_image')) {

                $filenameWithExt = $request->file('meta_image')->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $images = $request->file('meta_image');
                $extension = $request->file('meta_image')->getClientOriginalExtension();
                $fileName = 'meta_image_' . $filename . time() . rand() . '.' . $extension;




                $dir = 'uploads/upload_image';
                $upload_meta_img = Utility::upload_file($request, 'meta_image', $fileName, $dir, []);

                if ($upload_meta_img['flag'] == 1) {
                    $url = $upload_meta_img['url'];

                } else {
                    return redirect()->back()->with('error', $upload_meta_img['msg']);
                }
                $post['metaimage'] = $url;
            }
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
            $tab = 5;
            return redirect()->back()->with('success', __('SEO Successfully Updated.'))->with('tab', $tab);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));

        }

    }

    public function savePWA(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Update PWA Setting')) {
            $company_setting = getAdminAllSetting();

            $business_id = $id;
            $store = getStoreById(getCurrentStore());

            $post['enable_pwa_business'] = $request->pwa_business ?? 'off';
            if ($request->pwa_business == 'on') {
                $validator = \Validator::make(
                    $request->all(),
                    [
                        'pwa_app_title' => 'required|max:100',
                        'pwa_app_name' => 'required|max:50',
                        'pwa_app_background_color' => 'required|max:15',
                        'pwa_app_theme_color' => 'required|max:15',
                    ]
                );

                if ($validator->fails()) {
                    $messages = $validator->getMessageBag();
                    return redirect()->back()->with('error', $messages->first());
                }

                $company_favicon = get_module_img('Marketing');
                $favicon=isset($company_favicon)?$company_favicon:'favicon.png';
                $lang = isset(\Auth::user()->lang)?\Auth::user()->lang:'en';


                if ($company_setting['enable_businesslink'] == 'on') {
                    $start_url = route('get.vcard', ['slug' => $store->slug]);
                } elseif ($company_setting['enable_domain'] == 'on') {
                    $start_url = 'https://' . $company_setting['domains'] . '/';
                } else {
                    $start_url = 'https://' . $company_setting['subdomain'] . '/';
                }
                $manifest =
                '{
                    "lang": "' . $store['lang'] . '",
                    "name": "' . $request->pwa_app_title . '",
                    "short_name": "' . $request->pwa_app_name . '",
                    "start_url": "' .$start_url . '",
                    "display": "standalone",
                    "background_color": "' . $request->pwa_app_background_color . '",
                    "theme_color": "' . $request->pwa_app_theme_color . '",
                    "orientation": "portrait",
                    "categories": [
                        "shopping"
                    ],
                    "icons": [
                        {
                            "src":  "' . $favicon . '",
                            "sizes": "80x80",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src":  "' . $favicon . '",
                            "sizes": "128x128",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "144x144",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "152x152",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "192x192",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "256x256",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "512x512",
                            "type": "image/png",
                            "purpose": "any"
                        },
                        {
                            "src": "' . $favicon . '",
                            "sizes": "1024x1024",
                            "type": "image/png",
                            "purpose": "any"
                        }
                    ]
                }';


                if (!file_exists('storage/uploads/theme_app/store_' . $id)) {
                    mkdir(storage_path('uploads/theme_app/store_' . $id), 0777, true);
                }
                if (!file_exists('storage/uploads/theme_app/store_' . $id . '/manifest.json')) {
                    fopen('storage/uploads/theme_app/store_' . $id . "/manifest.json", "w");
                }
                \File::put('storage/uploads/theme_app/store_' . $id . '/manifest.json', $manifest);

            }


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
            $tab = 6;
            return redirect()->back()->with('success', __('PWA Successfully Updated.'))->with('tab', $tab);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));

        }

    }

    public function saveCookiesetting(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Update Cookie Setting')) {
            if ($request->enable_cookie && $request->enable_cookie == 'on') {
                $cookieData['cookie_logging'] = $request->cookie_logging;
                $cookieData['cookie_title'] = $request->cookie_title;
                $cookieData['cookie_description'] = $request->cookie_description;
                $cookieData['strictly_cookie_title'] = $request->strictly_cookie_title;
                $cookieData['strictly_cookie_description'] = $request->strictly_cookie_description;
                $cookieData['more_information_description'] = $request->more_information_description;
                $cookieData['contactus_url'] = $request->contactus_url;


                $post["is_gdpr_enabled"] = $request->enable_cookie;
                $post["gdpr_text"] = json_encode($cookieData);
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
            } else {
                $post["is_gdpr_enabled"] = $request->enable_cookie;
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
            }
            $tab = 7;
            return redirect()->back()->with('success', __('Cookie-Setting Successfully Updated.'))->with('tab', $tab);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));

        }
    }


    //Custom Qr code
    public function saveCustomQrsetting(Request $request, $id)
    {
        if (auth()->user() && auth()->user()->isAbleTo('Update QR Setting')) {
            $business = Storeqr::where('id', getCurrentStore())->first();

            if ($request->hasFile('image')) {
                $filenameWithExt = $request->file('image')->getClientOriginalName();
                $filename = pathinfo($filenameWithExt, PATHINFO_FILENAME);
                $extension = $request->file('image')->getClientOriginalExtension();
                $fileName = $filename . '_' . time() . '.' . $extension;
                $dir = 'uploads/qrcode';
                $upload_qr = Utility::upload_file($request, 'image', $fileName, $dir, []);
                if ($upload_qr['flag'] == 1) {
                    $url = $upload_qr['url'];
                } else {
                    return redirect()->back()->with('error', $upload_qr['msg']);
                }
                $qrImage = $url;
            }
            if (empty($business)) {
                $business = new Storeqr();

            }

            if (!isset($fileName)) {
                $qrImage = isset($business->image) ? $business->image : null;
            }

            $business = Storeqr::updateOrCreate(
                [
                    'store_id' => $id,
                    
                ],
                [
                    'foreground_color' => $request->foreground_color ?? '#000000',
                    'background_color' => $request->background_color ?? '#ffffff',
                    'radius' => $request->radius ?? 26,
                    'qr_type' => $request->qr_type ?? 0,
                    'qr_text' => $request->qr_text ?? 'Vcard',
                    'qr_text_color' => $request->qr_text_color ?? '#f50a0a',
                    'size' => $request->size ?? 9,
                    'image' => $qrImage ?? null,
                ]
            );
            $tab = 8;
            return redirect()->back()->with('success', __('QrCode generated successfully'))->with('tab', $tab);
        } else {
            return redirect()->back()->with('error', __('Permission denied.'));

        }

    }
    public function cardCookieConsent(Request $request)
    {
        $data = Store::where('slug', '=', $request->slug)->first();

        $filename = '';
        $filename = $data->slug . '.csv';
        $settings = json_decode($data->gdpr_text);

        if ($request['cookie']) {
            if ($data->is_gdpr_enabled == "on" && $settings->cookie_logging == "on") {

                $allowed_levels = ['necessary', 'analytics', 'targeting'];
                $levels = array_filter($request['cookie'], function ($level) use ($allowed_levels) {
                    return in_array($level, $allowed_levels);
                });

                $whichbrowser = new \WhichBrowser\Parser($_SERVER['HTTP_USER_AGENT']);
                // Generate new CSV line
                $browser_name = $whichbrowser->browser->name ?? null;
                $os_name = $whichbrowser->os->name ?? null;
                $browser_language = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? mb_substr($_SERVER['HTTP_ACCEPT_LANGUAGE'], 0, 2) : null;
                $device_type = GetDeviceType($_SERVER['HTTP_USER_AGENT']);

                 $ip = $_SERVER['REMOTE_ADDR'];
               //$ip = '49.36.83.154';
                $query = @unserialize(file_get_contents('http://ip-api.com/php/' . $ip));


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
                    isset($query) ? $query['country'] : '',
                    isset($query) ? $query['region'] : '',
                    isset($query) ? $query['regionName'] : '',
                    isset($query) ? $query['city'] : '',
                    isset($query) ? $query['zip'] : '',
                    isset($query) ? $query['lat'] : '',
                    isset($query) ? $query['lon'] : ''
                ]);
                if (!check_file('/uploads/sample/' . $filename . '.csv')) {
                    $first_line = 'IP,Date,Time,Accepted-cookies,Device type,Browser anguage,Browser name,OS Name,Country,Region,RegionName,City,Zipcode,Lat,Lon';
                    file_put_contents(base_path() . '/uploads/sample/' . $filename, $first_line . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
                file_put_contents(base_path() . '/uploads/sample/' . $filename, $new_line . PHP_EOL, FILE_APPEND | LOCK_EX);


                return response()->json('success');
            }
            return response()->json('error');
        }
        return redirect()->back();
    }

    public function getVcardDownload($slug)
    {
        $business = Store::where('slug', '=', $slug)->first();
        $vcard = new VCard();
        $lastname = '';
        $firstname = $business->name ?? '';
        $additional = '';
        $prefix = '';
        $suffix = '';
        // add personal data
        $vcard->addName($lastname, $firstname, $additional, $prefix, $suffix);

        // add work data
        $vcard->addCompany($business->name);
        $vcard->addRole($business->Company);


        $cardLogo = isset($business->logo) && !empty($business->logo) ? get_file($business->logo) : asset('packages/workdo/VCard/src/Resources/assets/custom/img/logo-placeholder-image-21.png');
        $cardLogoResponse = Http::get($cardLogo);
        if ($cardLogoResponse->successful()) {
            $cardLogoData = $cardLogoResponse->body();
            $localImagePath = public_path('card/card_logo.jpg'); // Include the desired filename in the path
            $directory = pathinfo($localImagePath, PATHINFO_DIRNAME);

            // Create the directory if it doesn't exist
            if (!file_exists($directory)) {
                mkdir($directory, 0777, true);
            }
            file_put_contents($localImagePath, $cardLogoData);
            $vcard->addPhoto($localImagePath);

        }
        $contacts = ContactInfo::where('store_id', $business->id)->first();

        if (!empty($contacts) && !empty($contacts->content)) {
            if (isset($contacts['is_enabled']) && $contacts['is_enabled'] == '1') {
                $contact = json_decode($contacts->content, true);
                foreach ($contact as $key => $val) {
                    foreach ($val as $key2 => $val2) {
                        if ($key2 == 'Email') {
                            $vcard->addEmail($val2);
                        }
                        if ($key2 == 'Phone') {
                            $vcard->addPhoneNumber($val2, 'TYPE#WORK,VOICE');
                        }
                        if ($key2 == 'Whatsapp') {
                            $vcard->addPhoneNumber($val2, 'WORK');
                        }
                        if ($key2 == 'Web_url') {
                            $vcard->addURL($val2);
                        }
                    }
                }
            }
        }
        $sociallinks = Social::where('store_id', $business->id)->first();
        $social_content = [];
        if (!empty($sociallinks->content)) {
            $social_content = json_decode($sociallinks->content);
        }
        if (!is_null($social_content) && !is_null($sociallinks)) {
            if (isset($sociallinks['is_enabled']) && $sociallinks['is_enabled'] == '1') {
                foreach ($social_content as $social_key => $social_val) {
                    foreach ($social_val as $social_key1 => $social_val1) {
                        if ($social_key1 != 'id') {
                            $vcard->addURL($social_val1, 'TYPE=' . $social_key1);
                        }
                    }
                }
            }
        }

        $path = public_path('/card');

        \File::delete($path);
        if (!is_dir($path)) {
            \File::makeDirectory($path, 0777);
        }
        $vcard->setSavePath($path);

        $vcard->save();
        $file = $vcard->getFilename() . '.' . $vcard->getFileExtension();
        self::download($path . '/' . $file);

    }

    function download($file)
    {
        if (file_exists($file)) {
            $iPhone = stripos($_SERVER['HTTP_USER_AGENT'], "iPhone");
            header('Content-Description: File Transfer');
            if ($iPhone) {
                header('Content-Type: text/vcard');
            } else {
                header('Content-Type: application/octet-stream');
            }
            header('Content-Disposition: attachment; filename=' . basename($file));
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
            flush();
            readfile($file);
            exit;
        }
    }


    public function cardpdf($slug)
    {
        $business = $store = getStore($slug);


        if (!is_null($business)) {
            \App::setLocale($business->default_language);
            $is_slug = "true";
            $is_pdf = "true";
            $businessfields = Storefield::getFields();
            $businesshours = BusinessHours::where('store_id', $business->id)->first();
            $appoinment = Appoinment::where('store_id', $business->id)->first();
            $appoinment_hours = [];
            if (!empty($appoinment->content)) {
                $appoinment_hours = json_decode($appoinment->content);
            }

            $services = Service::where('store_id', $business->id)->first();
            $services_content = [];
            if (!empty($services->content)) {
                $services_content = json_decode($services->content);
            }

            $testimonials = Testimonial::where('store_id', $business->id)->first();
            $testimonials_content = [];
            if (!empty($testimonials->content)) {
                $testimonials_content = json_decode($testimonials->content);
            }

            $contactinfo = ContactInfo::where('store_id', $business->id)->first();
            $contactinfo_content = [];
            if (!empty($contactinfo->content)) {
                $contactinfo_content = json_decode($contactinfo->content);
            }

            $sociallinks = Social::where('store_id', $business->id)->first();
            $social_content = [];
            if (!empty($sociallinks->content)) {
                $social_content = json_decode($sociallinks->content);
            }

            //Gallery
            $gallery = Gallery::where('store_id', $business->id)->first();
            $gallery_contents = [];
            if (!empty($gallery->content)) {
                $gallery_contents = json_decode($gallery->content);
            }


            $customhtml = $customhtml = $company_setting = getAdminAllSetting($business->created_by, $business->id);

            $days = BusinessHours::$days;
            $business_hours = '';
            if (!empty($businesshours->content)) {
                $business_hours = json_decode($businesshours->content);
            }

            $value = json_decode($store->card_theme);

            $storefields = $businessfields = Storefield::getFields();
            if (json_decode($business->card_theme) == NULL) {
                $card_order = [];
                $card_order['theme'] = $business->card_theme;
                $card_order['order'] = vcardstore::getDefaultThemeOrder($business->card_theme);
                $business->card_theme = json_encode($card_order);
                $business->save();
            }
            $card_theme = json_decode($business->card_theme);
            $card_theme = json_decode($business->card_theme, true) ?? [];
            $card_theme['theme'] = $card_theme['theme'] ?? "theme1";
            $card_theme['order'] = VcardStore::getDefaultThemeOrder($card_theme['theme'] ?? "theme1");


            $PixelFields = PixelFields::where('store_id', $business->id)->get();
            $pixelScript = [];
            foreach ($PixelFields as $pixel) {

                if (!$pixel->disabled) {
                    $pixelScript[] = VcardStore::pixelSourceCode($pixel['platform'], $pixel['pixel_id']);
                }
            }
            //Product
            $products = CardProduct::where('store_id', $business->id)->first();
            $products_content = [];
            if (!empty($products->content)) {
                $products_content = json_decode($products->content);
            }
            $cardPayment = CardPayment::cardPaymentData($business->id);
            $cardPayment_content = [];
            if (!empty($cardPayment->content)) {
                $cardPayment_content = json_decode($cardPayment->content);
            }
            $device_type = VcardStore::GetDeviceType($_SERVER['HTTP_USER_AGENT']);
            $themeOne = VcardStore::themeOne();
            $business = $store;
            $stringid = $store->id;
            $color = isset($store->theme_color) ? substr($store->theme_color, 0, 6) : "color1";

            $SITE_RTL = getAdminAllSetting($store->created_by, $store->id);
            if (isset($SITE_RTL['SITE_RTL'])) {
                $SITE_RTL = $SITE_RTL['SITE_RTL'];
            } else {
                $SITE_RTL = 'off';
            }
            $company_favicon = get_module_img('Marketing');
            $a = VcardStore::card_cookie($store->slug);
            $url_link = route('get.vcard', ['slug' => $store->slug]);
            $social_no = 1;
            $appointment_no = 0;
            $service_row_no = 0;
            $testimonials_row_no = 0;
            $gallery_row_no = 0;
            $product_row_no = 0;
            $no = 1;


            $custom_html = Appoinment::Marketing('custom_html_text');
            $is_branding_enabled = false;
            $branding = Appoinment::Marketing('branding_text');
            $is_gdpr_enabled = false;
            $gdpr_text = Appoinment::Marketing('gdpr_text');
            $is_custom_html_enable = false;
            if (!is_null($custom_html) && !is_null($customhtml)) {
                Appoinment::Marketing('is_custom_html_enabled') == '1' ? ($is_custom_html_enable = true) : ($is_custom_html_enable = false);
            }
            if (!is_null(Appoinment::Marketing('is_branding_enabled')) && !is_null(Appoinment::Marketing('is_branding_enabled'))) {
                !empty(Appoinment::Marketing('is_branding_enabled')) && Appoinment::Marketing('is_branding_enabled') == 'on'
                    ? ($is_branding_enabled = true)
                    : ($is_branding_enabled = false);
            } else {
                $is_branding_enabled = false;
            }
            if (!is_null(Appoinment::Marketing('is_gdpr_enabled')) && !is_null(Appoinment::Marketing('is_gdpr_enabled'))) {
                !empty(Appoinment::Marketing('is_gdpr_enabled')) && Appoinment::Marketing('is_gdpr_enabled') == 'on'
                    ? ($is_gdpr_enabled = true)
                    : ($is_gdpr_enabled = false);
            }
            $metaImg = get_file(Appoinment::Marketing('metaimage'));
            $metaKeyword = Appoinment::Marketing('metakeyword');
            $metaDesc = Appoinment::Marketing('metadesc');
            $customCss = Appoinment::Marketing('customcss');
            $googleFonts = Appoinment::Marketing('google_fonts');
            $enablePWABusiness = Appoinment::Marketing('enable_pwa_business');
            $brandingText = Appoinment::Marketing('branding_text');
            return view('marketing::card.' . $card_theme["theme"] . '.index', compact('businessfields', 'a', 'brandingText', 'googleFonts', 'enablePWABusiness', 'customCss', 'metaImg', 'metaDesc', 'metaKeyword', 'social_no', 'custom_html', 'is_branding_enabled', 'branding', 'is_gdpr_enabled', 'gdpr_text', 'is_custom_html_enable', 'appointment_no', 'service_row_no', 'testimonials_row_no', 'gallery_row_no', 'product_row_no', 'no', 'url_link', 'company_favicon', 'SITE_RTL', 'business', 'color', 'stringid', 'themeOne', 'device_type', 'card_theme', 'store', 'contactinfo', 'contactinfo_content', 'appoinment_hours', 'appoinment', 'services_content', 'services', 'testimonials_content', 'testimonials', 'social_content', 'sociallinks', 'customhtml', 'businesshours', 'business_hours', 'business', 'days', 'is_slug', 'is_pdf', 'gallery', 'gallery_contents', 'pixelScript', 'products', 'products_content', 'cardPayment', 'cardPayment_content'));
        } else {
            return abort('403', __('The Link You Followed Has Expired'));
        }
    }

}
