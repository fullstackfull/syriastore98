<?php

namespace Workdo\ProductCatelog\app\Providers;

use App\Models\EmailTemplate;
use App\Models\EmailTemplateLang;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Support\ServiceProvider;
use Workdo\ProductCatelog\app\Http\Controllers\ProductCatelogController;
use Illuminate\Support\Facades\Cache;

class ViewComposer extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */

     public function boot(){
        view()->composer(['front_end.hooks.footer_link'], function ($view) {
            if (module_is_active('ProductCatelog')) {
                $slug = $view->getData()['slug'] ?? request()->route('storeSlug');
                $store = getStore($slug);
                if ($store) {
                    $user = Cache::remember('user_details_' . $store->created_by, 3600, function () use ($store) {
                        return User::find($store->created_by);
                    });
                    $enable_product_catelog = \App\Models\Utility::GetValueByName('enable_product_catelog', $store->id);
                    if (isset($enable_product_catelog) && $enable_product_catelog == 'on') {
                        $view->getFactory()->startPush('addCatelogRequestButton', view('product-catelog::product-catelog.product-catelog-button',compact('store')));
                    }
                }
            }
        });

        view()->composer(['AppSetting.index'], function ($view) {
            if (module_is_active('ProductCatelog')) {
                $setting = $view->getData()['setting'] ?? null;
                ProductCatelogController::AdminDefaultEmail();
                $languages         = Utility::languages();
                $emailTemplate     = EmailTemplate::where('name', 'Product Catelog')->where('created_by',\Auth::user()->id)->first();
                $view->getFactory()->startPush('appSettingEnableBtn', view('product-catelog::pages.catelogbutton', compact('setting')));
                if ($emailTemplate) {
                    $language = 'en';
                    $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('is_default','1')->first();
                    $EmailTemplates    = EmailTemplate::where('created_by',\Auth::user()->id)->get();


                    if (!isset($currEmailTempLang) || empty($currEmailTempLang)) {
                        $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('language', 'en')->first();
                        if ($currEmailTempLang) {
                            $currEmailTempLang->language = $language;
                        } else {
                            $currEmailTempLang = EmailTemplateLang::where('parent_id', $emailTemplate->id)->where('language', 'en')->first();
                        }

                    }

                    $app_setting_tab = session()->get('app_setting_tab');
                    if (empty($app_setting_tab)) {
                        $app_setting_tab = 'pills-home-tab';
                    }
                    $store = Store::find(getCurrentStore());
                    $enable_product_catelog = \App\Models\Utility::GetValueByName('enable_product_catelog', $store->theme_id, $store->id);
                    if (isset($enable_product_catelog) && $enable_product_catelog == 'on') {
                        $view->getFactory()->startPush('appSettingTab', view('product-catelog::product-catelog.product-catelog-tab', compact('app_setting_tab')));
                        $view->getFactory()->startPush('appSettingTabForm', view('product-catelog::product-catelog.product-catelog-section',compact('currEmailTempLang','emailTemplate','EmailTemplates','languages','app_setting_tab')));
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
