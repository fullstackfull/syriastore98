<?php

namespace workdo\ProductEnquiry\app\Providers;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Product;
use App\Models\Store;
use App\Models\Utility;
use Illuminate\Support\ServiceProvider;
use Workdo\ProductEnquiry\app\Models\ProductEnquiry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Workdo\ProductEnquiry\app\Http\Controllers\ProductEnquiryController;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

    public function boot()
    {
        view()->composer(['front_end.pages.product-detail'], function ($view) {
            if (module_is_active('ProductEnquiry')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                $product_slug = \Request::segment(3);
                $product = Product::where('slug', $product_slug)->first();
                if ($store) {
                    $enable_enquiry = Utility::GetValueByName('enable_product_enquiry', $store->id);
                    if (isset($enable_enquiry) && $enable_enquiry == 'on') {
                        $queries = ProductEnquiry::where('product_id', $product->id)->where('store_id', $store->id)->get();
                        $view->getFactory()->startPush('addEnquiryButton', view('product-enquiry::pages.button'));
                        $view->getFactory()->startPush('addEnquiryModel', view('product-enquiry::pages.enquiry_model', compact('product', 'slug', 'queries')));
                    }
                }
            }
        });

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('ProductEnquiry')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = Store::find(getCurrentStore());
                $product_slug = \Request::segment(3);
                $product = Product::where('slug', $product_slug)->first();
                if ($store) {
                    $setting = $view->getData()['setting'] ?? null;
                    $view->getFactory()->startPush('appSettingEnableBtn', view('product-enquiry::product-enquiry.enquirybutton', compact('setting')));
                    if (Auth::user()) {
                        ProductEnquiryController::EmailTemplateDefaultData();
                        ProductEnquiryController::userTemplateDefaultData(Auth::user()->id);
                    }
                    $user_id = Auth::user()->id ?? $store->id;
                    $language = 'en';
                    $languages = Utility::languages();
                    $emailTemplate = EmailTemplate::where('name', 'Product Enquiry')->where('created_by', $user_id)->first();
                    $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('is_default', '1')->first();
                    if (!isset($currEmailTempLang) || empty($currEmailTempLang)) {
                        $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('language', 'en')->first();
                        $currEmailTempLang->language = $language;
                    }

                    $app_setting_tab = session()->get('app_setting_tab');
                    if (empty($app_setting_tab)) {
                        $app_setting_tab = 'pills-home-tab';
                    }
                    if (isset($setting['enable_product_enquiry']) && $setting['enable_product_enquiry'] == 'on') {
                        $view->getFactory()->startPush('appSettingTab', view('product-enquiry::product-enquiry.enquirySettingTab', compact('setting', 'app_setting_tab')));
                        $view->getFactory()->startPush('appSettingTabForm', view('product-enquiry::product-enquiry.enquirySettingDetails', compact('setting', 'emailTemplate', 'languages', 'currEmailTempLang', 'language', 'app_setting_tab')));
                    }
                }
            }
        });
    }
    public function register()
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}
